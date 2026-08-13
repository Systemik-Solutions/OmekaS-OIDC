<?php
namespace Oidc\Controller;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Service\Builder\UserInfoServiceBuilder;
use Facile\OpenIDClient\Session\AuthSession;
use Laminas\Authentication\AuthenticationService;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Session\Container as SessionContainer;
use Laminas\Session\SessionManager;
use Laminas\View\Model\ViewModel;
use Oidc\Exception\NotConfiguredException;
use Oidc\Settings as S;
use Oidc\Stdlib\PublicUrl;
use Oidc\Stdlib\RedirectUrl;
use Omeka\Entity\User;
use Omeka\Settings\Settings;
use Omeka\Settings\UserSettings;

class OidcController extends AbstractActionController
{
    private const PRIVILEGE_ORDER = [
        'global_admin' => 60,
        'site_admin'   => 50,
        'editor'       => 40,
        'reviewer'     => 30,
        'author'       => 20,
        'researcher'   => 10,
    ];

    private $client;
    private $authentication;
    private $entityManager;
    private $settings;
    private $userSettings;
    private $sessionManager;
    private $logger;

    /**
     * Inject dependencies. The OIDC client is nullable because it cannot be
     * built until the module has been configured with valid IdP credentials.
     */
    public function __construct(
        ?ClientInterface $client,
        AuthenticationService $authentication,
        EntityManager $entityManager,
        Settings $settings,
        UserSettings $userSettings,
        SessionManager $sessionManager,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->authentication = $authentication;
        $this->entityManager = $entityManager;
        $this->settings = $settings;
        $this->userSettings = $userSettings;
        $this->sessionManager = $sessionManager;
        $this->logger = $logger;
    }

    /**
     * Begin the OIDC Authorization Code + PKCE flow. Generates state, nonce,
     * and PKCE verifier, stashes them in the session, then redirects the
     * browser to the IdP authorization endpoint.
     */
    public function loginAction()
    {
        if ($this->authentication->hasIdentity()) {
            return $this->redirect()->toRoute('top');
        }

        if (!$this->client) {
            return $this->denied('OIDC is not configured. Contact the administrator.');
        }

        $session = new SessionContainer('Oidc');

        // Do not carry a destination over from an abandoned login attempt.
        unset($session->returnUrl);
        $returnUrl = $this->params()->fromQuery('redirect');
        if (is_string($returnUrl) && $returnUrl !== '') {
            $validatedReturnUrl = $this->validateLocalUrl($returnUrl);
            if ($validatedReturnUrl !== null) {
                $session->returnUrl = $validatedReturnUrl;
            }
        }

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $codeVerifier = self::base64url(random_bytes(32));
        $codeChallenge = self::base64url(hash('sha256', $codeVerifier, true));

        $session->state = $state;
        $session->nonce = $nonce;
        $session->codeVerifier = $codeVerifier;

        $scopes = $this->settings->get(S::SCOPES, S::DEFAULTS[S::SCOPES]);
        if (!is_array($scopes)) {
            $scopes = S::DEFAULTS[S::SCOPES];
        }

        $metadata = $this->client->getMetadata();
        $authService = (new AuthorizationServiceBuilder())->build();

        $authParams = [
            'client_id' => $metadata->getClientId(),
            'redirect_uri' => $metadata->getRedirectUris()[0],
            'scope' => implode(' ', $scopes),
            'response_type' => 'code',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        // If the previous action was an explicit logout, force the IdP to
        // re-authenticate instead of silently reusing its existing session.
        // Flag set as a cookie because logoutAction destroys the PHP session.
        if (!empty($_COOKIE['oidc_force_reauth'])) {
            $authParams['prompt'] = 'login';
            setcookie('oidc_force_reauth', '', time() - 3600, '/');
        }

        $authUri = $authService->getAuthorizationUri($this->client, $authParams);

        return $this->redirect()->toUrl($authUri);
    }

    /**
     * Handle the IdP redirect after the user authenticates. Validates state
     * and nonce, exchanges the code for tokens, fetches userinfo, enforces
     * the access guard, resolves the role, provisions or syncs the user,
     * then writes the identity into Omeka's auth storage.
     */
    public function callbackAction()
    {
        if (!$this->client) {
            return $this->denied('OIDC is not configured.');
        }

        $session = new SessionContainer('Oidc');

        $expectedState = $session->state ?? null;
        $expectedNonce = $session->nonce ?? null;
        $codeVerifier  = $session->codeVerifier ?? null;
        $returnUrl     = $session->returnUrl ?? null;
        unset($session->state, $session->nonce, $session->codeVerifier, $session->returnUrl);

        $stateParam = $this->params()->fromQuery('state');
        if (!$expectedState || !$stateParam || !hash_equals($expectedState, (string) $stateParam)) {
            return $this->denied('Invalid OIDC callback state.');
        }

        $authService = (new AuthorizationServiceBuilder())->build();
        $userInfoService = (new UserInfoServiceBuilder())->build();

        $callbackParams = array_merge(
            (array) $this->params()->fromQuery(),
            (array) $this->params()->fromPost()
        );

        $authSession = AuthSession::fromArray([
            'state' => $expectedState,
            'nonce' => $expectedNonce,
            'code_verifier' => $codeVerifier,
        ]);

        try {
            $tokenSet = $authService->callback(
                $this->client,
                $callbackParams,
                $this->client->getMetadata()->getRedirectUris()[0],
                $authSession
            );
            $idClaims = $tokenSet->claims();
        } catch (\Throwable $e) {
            $this->logger->err('OIDC authorization callback failed.', ['exception' => $e]);
            return $this->denied('OIDC sign-in failed. Please try again.');
        }

        if (!$expectedNonce || ($idClaims['nonce'] ?? null) !== $expectedNonce) {
            return $this->denied('OIDC nonce mismatch.');
        }

        try {
            $userInfo = $userInfoService->getUserInfo($this->client, $tokenSet);
        } catch (\Throwable $e) {
            $this->logger->err('OIDC userinfo request failed.', ['exception' => $e]);
            return $this->denied('Sign-in could not be completed. Please try again.');
        }
        if (!is_array($userInfo)) {
            $this->logger->err('OIDC userinfo response was not an array; login denied.');
            return $this->denied('Sign-in could not be completed. Please try again.');
        }
        $claims = array_merge($idClaims, $userInfo);

        $guardClaim = $this->settings->get(S::ACCESS_GUARD_CLAIM);
        $guardValue = $this->settings->get(S::ACCESS_GUARD_VALUE);
        $hasGuardClaim = is_string($guardClaim) && trim($guardClaim) !== '';
        $hasGuardValue = is_string($guardValue) && trim($guardValue) !== '';
        if ($hasGuardClaim !== $hasGuardValue) {
            $this->logger->err('OIDC access guard is partially configured; login denied.');
            return $this->denied('OIDC access control is not configured correctly. Contact the administrator.');
        }
        if ($hasGuardClaim && !$this->claimContains($claims, $guardClaim, $guardValue)) {
            return $this->denied('You are not authorised to access this site. Contact the administrator.');
        }

        $sub = $claims['sub'] ?? null;
        $iss = $claims['iss'] ?? null;
        if (!$sub || !$iss) {
            return $this->denied('OIDC response missing required identifier.');
        }

        $role = $this->resolveRole($claims);
        if ($role === null) {
            return $this->denied('Your account has no role assigned for this site. Contact the administrator.');
        }

        $user = $this->findUserBySubIss($sub, $iss);
        if ($user && !$user->isActive()) {
            return $this->denied('Your account is inactive. Contact the administrator.');
        }
        if ($this->claimedEmailBelongsToAnotherUser($claims, $user)) {
            $this->logger->warn('OIDC login denied because the claimed email address belongs to another Omeka user.');
            return $this->denied('An account with this email address already exists. Contact the administrator.');
        }

        // Rotate the pre-authentication session before changing any user data
        // or writing an authenticated identity. Treat both exceptions and an
        // unchanged session ID as a failed rotation.
        $previousSessionId = $this->sessionManager->getId();
        try {
            $this->sessionManager->regenerateId();
        } catch (\Throwable $e) {
            $this->logger->crit('OIDC session ID regeneration failed; login denied.', ['exception' => $e]);
            return $this->denied('Sign-in could not be completed securely. Please try again.');
        }
        $newSessionId = $this->sessionManager->getId();
        if ($previousSessionId === '' || $newSessionId === '' || hash_equals($previousSessionId, $newSessionId)) {
            $this->logger->crit('OIDC session ID did not change after regeneration; login denied.');
            return $this->denied('Sign-in could not be completed securely. Please try again.');
        }

        try {
            if (!$user) {
                $user = $this->provisionUser($claims, $role);
            } else {
                $this->syncUser($user, $claims, $role);
            }
        } catch (UniqueConstraintViolationException $e) {
            // The preflight check above provides a clear result in the normal
            // case; this catch also closes the race between that read and the
            // database write.
            $this->logger->err('OIDC user save failed because of a unique constraint violation.', ['exception' => $e]);
            return $this->denied('An account with this email address already exists. Contact the administrator.');
        }

        $this->storeSessionMetadata($user, $sub, $iss, $claims);

        // Write user to auth storage:
        $this->authentication->getStorage()->write($user);

        $idToken = $tokenSet->getIdToken();
        if (is_string($idToken) && $idToken !== '') {
            $logoutSession = new SessionContainer('Oidc');
            $logoutSession->idToken = $idToken;
        }

        $this->getEventManager()->trigger('user.login', $user);

        $target = $this->resolvePostLoginUrl($returnUrl);
        return $this->createCallbackView($target);
    }

    /**
     * Render the post-authentication transition as a standalone document.
     * Besides avoiding a complete document nested inside Omeka's layout, the
     * response must not be cached or send the callback's code/state URL as a
     * referrer when navigating to the final destination.
     */
    private function createCallbackView(string $target): ViewModel
    {
        $headers = $this->getResponse()->getHeaders();
        $headers->addHeaderLine('Cache-Control', 'no-store');
        $headers->addHeaderLine('Referrer-Policy', 'no-referrer');

        $view = new ViewModel(['target' => $target]);
        $view->setTemplate('oidc/oidc/callback');
        $view->setTerminal(true);
        return $view;
    }

    /**
     * Clear the local session and, when the IdP advertises an end-session
     * endpoint, redirect to it with id_token_hint so the user is also signed
     * out at the IdP (RP-initiated logout). Falls back to the site root.
     */
    public function logoutAction()
    {
        $logoutSession = new SessionContainer('Oidc');
        $idToken = $logoutSession->idToken ?? null;
        unset($logoutSession->idToken);

        $this->authentication->clearIdentity();
        $this->getEventManager()->trigger('user.logout');

        $endSessionUrl = null;
        if ($this->client) {
            try {
                $issuerMeta = $this->client->getIssuer()->getMetadata()->toArray();
                $endpoint = $issuerMeta['end_session_endpoint'] ?? null;
                if ($endpoint) {
                    $params = ['post_logout_redirect_uri' => $this->absoluteUrl('/')];
                    if (is_string($idToken) && $idToken !== '') {
                        $params['id_token_hint'] = $idToken;
                    }
                    $endSessionUrl = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params);
                }
            } catch (\Throwable $e) {
                $this->logger->warn('OIDC end-session endpoint discovery failed; using local logout.', ['exception' => $e]);
                $endSessionUrl = null;
            }
        }

        setcookie('oidc_force_reauth', '1', time() + 600, '/');

        try {
            $this->sessionManager->destroy(['clear_storage' => true]);
        } catch (\Throwable $e) {
            $this->logger->crit('OIDC local session destruction failed during logout.', ['exception' => $e]);
            throw $e;
        }

        return $this->redirect()->toUrl($endSessionUrl ?: $this->absoluteUrl('/'));
    }

    /**
     * Look up the Omeka user whose stored oidc_sub and oidc_iss user-settings
     * match the given pair. The sub+iss tuple is the only stable identifier
     * across IdPs and email changes.
     */
    private function findUserBySubIss(string $sub, string $iss): ?User
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT s_sub.user_id
                FROM user_setting s_sub
                INNER JOIN user_setting s_iss
                    ON s_iss.user_id = s_sub.user_id AND s_iss.id = :iss_key
                WHERE s_sub.id = :sub_key
                  AND BINARY s_sub.value = :sub_val
                  AND BINARY s_iss.value = :iss_val
                LIMIT 1';
        // user_setting.value uses Doctrine's json_array type, so scalar
        // strings are stored as JSON string literals. BINARY is required here
        // because sub and iss are case-sensitive OIDC identifiers, while the
        // table's default utf8mb4_unicode_ci collation is not.
        $userId = $conn->fetchOne($sql, [
            'sub_key' => 'oidc_sub',
            'iss_key' => 'oidc_iss',
            'sub_val' => json_encode($sub),
            'iss_val' => json_encode($iss),
        ]);
        if (!$userId) {
            return null;
        }
        return $this->entityManager->find(User::class, (int) $userId);
    }

    /**
     * Return true when the IdP-provided email is already assigned to an Omeka
     * account other than the OIDC identity being processed. Existing local
     * accounts are deliberately not linked implicitly.
     */
    private function claimedEmailBelongsToAnotherUser(array $claims, ?User $user): bool
    {
        $email = $claims['email'] ?? null;
        if (!is_string($email) || $email === '') {
            return false;
        }

        $owner = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        if (!$owner) {
            return false;
        }

        return !$user || (int) $owner->getId() !== (int) $user->getId();
    }

    /**
     * Just-in-time create a new Omeka user from OIDC claims. Sets a random
     * 32-byte password the user can never know — the account is OIDC-only —
     * and flags it with the oidc_managed user-setting.
     */
    private function provisionUser(array $claims, string $role): User
    {
        $user = new User();
        $user->setEmail($claims['email'] ?? sprintf('%s@%s', substr(bin2hex(random_bytes(8)), 0, 12), 'oidc.local'));
        $user->setName($this->resolveDisplayName($claims));
        $user->setRole($role);
        $user->setIsActive(true);
        $user->setPassword(bin2hex(random_bytes(32)));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->userSettings->set('oidc_managed', true, $user->getId());
        return $user;
    }

    /**
     * Update an existing user's name, email, and role from the latest claims.
     * Only flushes when something actually changed. Callers must reject the
     * login before this point when the current claims yield no role.
     */
    private function syncUser(User $user, array $claims, string $role): void
    {
        $name = $this->resolveDisplayName($claims);
        $changed = false;
        if ($name && $name !== $user->getName()) {
            $user->setName($name);
            $changed = true;
        }
        $email = $claims['email'] ?? null;
        if ($email && $email !== $user->getEmail()) {
            $user->setEmail($email);
            $changed = true;
        }
        if ($role !== $user->getRole()) {
            $user->setRole($role);
            $changed = true;
        }
        if ($changed) {
            $this->entityManager->flush();
        }
    }

    /**
     * Persist per-login metadata as user-settings: connection method, IdP,
     * timestamp, sub/iss, and any claims listed in the claims_display config.
     * This is what powers the OIDC metadata box on the user admin page.
     */
    private function storeSessionMetadata(User $user, string $sub, string $iss, array $claims): void
    {
        $userId = $user->getId();
        $this->userSettings->set('connection_authenticator', 'Oidc', $userId);
        $this->userSettings->set('connection_idp', $iss, $userId);
        $this->userSettings->set('connection_last', (new DateTime())->format(DateTimeInterface::ATOM), $userId);
        $this->userSettings->set('oidc_sub', $sub, $userId);
        $this->userSettings->set('oidc_iss', $iss, $userId);

        $display = $this->settings->get(S::CLAIMS_DISPLAY, []);
        if (is_array($display)) {
            foreach (array_keys($display) as $claimName) {
                if (array_key_exists($claimName, $claims)) {
                    $this->userSettings->set($claimName, $claims[$claimName], $userId);
                }
            }
        }
    }

    /**
     * Decide which Omeka role the user should have based on their group
     * claim values (e.g. CILogon's isMemberOf). Substring-matches each
     * claim value against the roles_map; if multiple entries match, the
     * highest-privilege role wins. Falls back to role_default, or null
     * (deny) if no default is configured.
     */
    private function resolveRole(array $claims): ?string
    {
        $claimName = $this->settings->get(S::ROLE_CLAIM, S::DEFAULTS[S::ROLE_CLAIM]);
        $map = $this->settings->get(S::ROLES_MAP, []);
        $default = $this->settings->get(S::ROLE_DEFAULT, S::DEFAULTS[S::ROLE_DEFAULT]);

        $values = $claims[$claimName] ?? null;
        if (!is_array($values)) {
            $values = $values === null ? [] : [(string) $values];
        }

        $matched = [];
        if (is_array($map)) {
            foreach ($map as $key => $role) {
                foreach ($values as $value) {
                    if (is_string($value) && $key !== '' && str_contains($value, (string) $key)) {
                        $matched[] = $role;
                        break;
                    }
                }
            }
        }

        if (!$matched) {
            return $default ?: null;
        }

        usort($matched, function ($a, $b) {
            return (self::PRIVILEGE_ORDER[$b] ?? 0) <=> (self::PRIVILEGE_ORDER[$a] ?? 0);
        });
        return $matched[0];
    }

    /**
     * Access-guard helper: return true when at least one value of the given
     * claim contains the required substring. Used to gate access to this
     * Omeka instance (e.g. require "omeka-instance-a" in any isMemberOf value).
     */
    private function claimContains(array $claims, string $claimName, string $needle): bool
    {
        $value = $claims[$claimName] ?? null;
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $v) {
            if (is_string($v) && $needle !== '' && str_contains($v, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a display name from claims, preferring "name", then
     * given_name + family_name, then the local part of the email,
     * with a generic fallback so the user always has a label.
     */
    private function resolveDisplayName(array $claims): string
    {
        if (!empty($claims['name'])) {
            return (string) $claims['name'];
        }
        $given = trim((string) ($claims['given_name'] ?? ''));
        $family = trim((string) ($claims['family_name'] ?? ''));
        $full = trim($given . ' ' . $family);
        if ($full !== '') {
            return $full;
        }
        if (!empty($claims['email'])) {
            $parts = explode('@', (string) $claims['email'], 2);
            return $parts[0];
        }
        return 'OIDC User';
    }

    /**
     * Decide where to send the user after a successful login. Priority:
     * 1) the ?redirect query param captured at login start (if local),
     * 2) the configured post-login redirect setting (if local),
     * 3) the site root.
     */
    private function resolvePostLoginUrl(?string $returnUrl): string
    {
        if (is_string($returnUrl) && $returnUrl !== '') {
            $valid = $this->validateLocalUrl($returnUrl);
            if ($valid !== null) {
                return $valid;
            }
        }
        $configured = $this->settings->get(S::REDIRECT, S::DEFAULTS[S::REDIRECT]);
        if (is_string($configured) && $configured !== '' && $configured !== 'home') {
            $local = $this->validateLocalUrl($configured);
            if ($local !== null) {
                return $local;
            }
        }
        return $this->absoluteUrl('/');
    }

    /**
     * Open-redirect guard. Accepts site-relative paths (/items) or absolute
     * HTTP(S) URLs on the configured public origin. Safe absolute URLs are
     * rebuilt from validated components to avoid parser differences.
     */
    private function validateLocalUrl(string $url): ?string
    {
        $baseUrl = $this->settings->get(S::BASE_URL);
        $base = is_string($baseUrl) ? PublicUrl::components($baseUrl) : null;
        if ($base === null) {
            $this->logger->err('OIDC public base URL is missing or invalid; redirect rejected.');
            return null;
        }
        return RedirectUrl::normalizeForOrigin(
            $url,
            $base['scheme'],
            $base['host'],
            $base['port'],
            $base['path']
        );
    }

    /**
     * Build a URL from the administrator-pinned public base URL. A relative
     * root fallback is safer than consulting an attacker-controlled Host
     * header if settings are missing or corrupted.
     */
    private function absoluteUrl(string $path): string
    {
        $baseUrl = $this->settings->get(S::BASE_URL);
        $absolute = is_string($baseUrl) ? PublicUrl::appendPath($baseUrl, $path) : null;
        if ($absolute === null) {
            $this->logger->err('OIDC public base URL is missing or invalid; using a relative redirect.');
            return '/';
        }
        return $absolute;
    }

    /**
     * Show a flash error and redirect to the standard login page. Used for
     * every failure path in the OIDC flow (config missing, state mismatch,
     * access guard rejection, inactive account, no role, etc).
     */
    private function denied(string $message)
    {
        $messenger = $this->plugin('messenger');
        if ($messenger) {
            $messenger->addError($message);
        }
        return $this->redirect()->toRoute('login');
    }

    /**
     * URL-safe base64 encoding (RFC 4648 §5) used by PKCE for both the
     * code_verifier and the SHA-256 code_challenge.
     */
    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
