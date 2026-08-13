<?php
namespace Oidc\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Oidc\Settings as S;

class ConfigForm extends Form
{
    private const ACCESS_GUARD_PAIR_ERROR = 'Access-guard claim and access-guard value must either both be set or both be blank.';

    // TODO. for secret find somethig to fill
    private const ROLE_CHOICES = [
        ''             => '[ none ]',
        'global_admin' => 'global_admin',
        'site_admin'   => 'site_admin (Supervisor)',
        'editor'       => 'editor',
        'reviewer'     => 'reviewer',
        'author'       => 'author',
        'researcher'   => 'researcher',
    ];
    public function init(): void
    {
        $this->add([
            'name' => S::IDP_DISCOVERY_URL,
            'type' => Element\Url::class,
            'options' => [
                'label' => 'IdP discovery URL', // @translate
                'info'  => 'OpenID Connect discovery document URL.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-idp-discovery-url',
                'required' => true,
                'placeholder' => 'https://cilogon.org/.well-known/openid-configuration',
            ],
        ]);

        $this->add([
            'name' => S::CLIENT_ID,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Client ID', // @translate
                'info'  => 'Registered OIDC client identifier issued by the IdP.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-client-id',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => S::CLIENT_SECRET,
            'type' => Element\Password::class,
            'options' => [
                'label' => 'Client secret', // @translate
                'info'  => 'Client secret issued by the IdP. Leave blank to keep the existing value.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-client-secret',
                'autocomplete' => 'new-password',
            ],
        ]);

        $this->add([
            'name' => S::SCOPES,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Scopes', // @translate
                'info'  => 'Space-separated OIDC scopes. Must include "openid".', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-scopes',
                'required' => true,
                'placeholder' => 'openid email profile org.cilogon.userinfo',
            ],
        ]);

        $this->add([
            'name' => S::ROLE_CLAIM,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Role claim', // @translate
                'info'  => 'Claim name carrying the user\'s group membership values (CILogon: isMemberOf).', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-role-claim',
                'required' => true,
                'placeholder' => 'isMemberOf',
            ],
        ]);

        $this->add([
            'name' => S::ROLES_MAP,
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Roles map', // @translate
                'info'  => 'One mapping per line as "group_substring = omeka_role". Substring is matched anywhere within a role-claim value.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-roles-map',
                'rows' => 6,
                'placeholder' => "omeka-instance-a-editor = editor\nomeka-instance-a-author = author",
            ],
        ]);

        $this->add([
            'name' => S::ROLE_DEFAULT,
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Default role', // @translate
                'info'  => 'Fallback role assigned when no roles_map entry matches. Leave empty to deny access in that case.', // @translate
                'value_options' => self::ROLE_CHOICES,
                'empty_option' => null,
            ],
            'attributes' => [
                'id' => 'oidc-role-default',
            ],
        ]);

        $this->add([
            'name' => S::ACCESS_GUARD_CLAIM,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Access-guard claim', // @translate
                'info'  => 'Per-instance access guard. Claim to inspect (typically isMemberOf). Leave blank to disable.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-access-guard-claim',
            ],
        ]);

        $this->add([
            'name' => S::ACCESS_GUARD_VALUE,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Access-guard value', // @translate
                'info'  => 'Required substring that must appear in at least one value of the access-guard claim.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-access-guard-value',
                'placeholder' => 'omeka-instance-a',
            ],
        ]);

        $this->add([
            'name' => S::HIDE_LOCAL_LOGIN,
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Hide local login form', // @translate
                'info'  => 'Hide the username/password fields on the login page. The local form remains accessible at its URL as a super-admin fallback.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-hide-local-login',
            ],
        ]);

        $this->add([
            'name' => S::REDIRECT,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Post-login redirect', // @translate
                'info'  => 'Where to land users after successful login. Use "home", a site-relative path (/items), or an absolute URL on this host.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-redirect',
                'placeholder' => 'home',
            ],
        ]);

        $this->add([
            'name' => S::CLAIMS_DISPLAY,
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Claims to display', // @translate
                'info'  => 'One per line as "claim = label". Stored on every login. Empty label = stored but not displayed in the user admin UI.', // @translate
            ],
            'attributes' => [
                'id' => 'oidc-claims-display',
                'rows' => 6,
                'placeholder' => "email = Email Address\nname = Display Name",
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => S::CLIENT_SECRET,
            'required' => false,
            'allow_empty' => true,
        ]);
        $inputFilter->add([
            'name' => S::ACCESS_GUARD_CLAIM,
            'required' => false,
            'allow_empty' => true,
        ]);
        $inputFilter->add([
            'name' => S::ACCESS_GUARD_VALUE,
            'required' => false,
            'allow_empty' => true,
        ]);
        $inputFilter->add([
            'name' => S::ROLE_DEFAULT,
            'required' => false,
            'allow_empty' => true,
        ]);
        $inputFilter->add([
            'name' => S::ROLES_MAP,
            'required' => false,
            'allow_empty' => true,
        ]);
        $inputFilter->add([
            'name' => S::CLAIMS_DISPLAY,
            'required' => false,
            'allow_empty' => true,
        ]);
    }

    /**
     * Add cross-field validation for the access guard. Configuring only one
     * half of the pair would otherwise make the runtime guard ambiguous.
     */
    public function isValid(): bool
    {
        $isValid = parent::isValid();
        $claim = $this->get(S::ACCESS_GUARD_CLAIM)->getValue();
        $value = $this->get(S::ACCESS_GUARD_VALUE)->getValue();
        $hasClaim = is_string($claim) && trim($claim) !== '';
        $hasValue = is_string($value) && trim($value) !== '';

        if ($hasClaim !== $hasValue) {
            $missingElement = $hasClaim ? S::ACCESS_GUARD_VALUE : S::ACCESS_GUARD_CLAIM;
            $this->get($missingElement)->setMessages([self::ACCESS_GUARD_PAIR_ERROR]);
            return false;
        }

        return $isValid;
    }

    /**
     * Convert stored settings (with array-typed fields) into form-field values
     * (where arrays are flattened to text strings).
     */
    public static function settingsToFormData(array $settings): array
    {
        if (isset($settings[S::SCOPES]) && is_array($settings[S::SCOPES])) {
            $settings[S::SCOPES] = implode(' ', $settings[S::SCOPES]);
        }
        if (isset($settings[S::ROLES_MAP]) && is_array($settings[S::ROLES_MAP])) {
            $settings[S::ROLES_MAP] = self::arrayToLines($settings[S::ROLES_MAP]);
        }
        if (isset($settings[S::CLAIMS_DISPLAY]) && is_array($settings[S::CLAIMS_DISPLAY])) {
            $settings[S::CLAIMS_DISPLAY] = self::arrayToLines($settings[S::CLAIMS_DISPLAY]);
        }
        if (isset($settings[S::CLIENT_SECRET])) {
            $settings[S::CLIENT_SECRET] = '';
        }
        if (isset($settings[S::ROLE_DEFAULT]) && $settings[S::ROLE_DEFAULT] === null) {
            $settings[S::ROLE_DEFAULT] = '';
        }
        if (isset($settings[S::HIDE_LOCAL_LOGIN])) {
            $settings[S::HIDE_LOCAL_LOGIN] = (bool) $settings[S::HIDE_LOCAL_LOGIN];
        }
        return $settings;
    }

    /**
     * Convert validated form data (with string-typed array fields) into
     * settings-shaped values ready for Configurator::applyValidated().
     */
    public static function formDataToSettings(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, S::KEYS, true)) {
                continue;
            }
            $out[$key] = $value;
        }

        if (isset($out[S::SCOPES]) && is_string($out[S::SCOPES])) {
            $out[S::SCOPES] = array_values(array_filter(
                preg_split('/\s+/', trim($out[S::SCOPES])) ?: [],
                fn ($s) => $s !== ''
            ));
        }
        if (isset($out[S::ROLES_MAP]) && is_string($out[S::ROLES_MAP])) {
            $out[S::ROLES_MAP] = self::linesToArray($out[S::ROLES_MAP]);
        }
        if (isset($out[S::CLAIMS_DISPLAY]) && is_string($out[S::CLAIMS_DISPLAY])) {
            $out[S::CLAIMS_DISPLAY] = self::linesToArray($out[S::CLAIMS_DISPLAY]);
        }
        if (array_key_exists(S::ROLE_DEFAULT, $out) && $out[S::ROLE_DEFAULT] === '') {
            $out[S::ROLE_DEFAULT] = null;
        }
        foreach ([S::ACCESS_GUARD_CLAIM, S::ACCESS_GUARD_VALUE] as $k) {
            if (array_key_exists($k, $out) && is_string($out[$k])) {
                $out[$k] = trim($out[$k]);
                if ($out[$k] === '') {
                    $out[$k] = null;
                }
            }
        }
        if (array_key_exists(S::HIDE_LOCAL_LOGIN, $out)) {
            $out[S::HIDE_LOCAL_LOGIN] = (bool) $out[S::HIDE_LOCAL_LOGIN];
        }
        if (array_key_exists(S::CLIENT_SECRET, $out) && $out[S::CLIENT_SECRET] === '') {
            unset($out[S::CLIENT_SECRET]);
        }
        return $out;
    }

    private static function arrayToLines(array $map): string
    {
        $lines = [];
        foreach ($map as $key => $value) {
            $lines[] = $key . ' = ' . $value;
        }
        return implode("\n", $lines);
    }

    private static function linesToArray(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
