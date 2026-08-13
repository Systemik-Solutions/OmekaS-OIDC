<?php
namespace Oidc\Service;

use Oidc\Exception\InvalidConfigurationException;
use Oidc\Settings as S;
use Oidc\Stdlib\PublicUrl;
use Oidc\Stdlib\RedirectUrl;
use Omeka\Settings\Settings;

class Configurator
{
    private const SHORT_KEY_MAP = [
        'idp_discovery_url'   => S::IDP_DISCOVERY_URL,
        'base_url'            => S::BASE_URL,
        'client_id'           => S::CLIENT_ID,
        'client_secret'       => S::CLIENT_SECRET,
        'scopes'              => S::SCOPES,
        'role_claim'          => S::ROLE_CLAIM,
        'roles_map'           => S::ROLES_MAP,
        'role_default'        => S::ROLE_DEFAULT,
        'access_guard_claim'  => S::ACCESS_GUARD_CLAIM,
        'access_guard_value'  => S::ACCESS_GUARD_VALUE,
        'hide_local_login'    => S::HIDE_LOCAL_LOGIN,
        'redirect'            => S::REDIRECT,
        'claims_display'      => S::CLAIMS_DISPLAY,
    ];

    private const ALLOWED_ROLES = [
        'global_admin', 'site_admin', 'editor', 'reviewer', 'author', 'researcher',
    ];

    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function apply(array $data): void
    {
        $normalized = [];
        $longKeys = array_values(self::SHORT_KEY_MAP);
        foreach ($data as $key => $value) {
            if (isset(self::SHORT_KEY_MAP[$key])) {
                $normalized[self::SHORT_KEY_MAP[$key]] = $value;
            } elseif (in_array($key, $longKeys, true)) {
                $normalized[$key] = $value;
            } else {
                throw new InvalidConfigurationException(sprintf('Unknown OIDC setting "%s".', $key));
            }
        }
        $this->applyValidated($normalized);
    }

    public function applyValidated(array $data): void
    {
        $this->validate($data);
        if (isset($data[S::BASE_URL])) {
            $data[S::BASE_URL] = PublicUrl::normalize($data[S::BASE_URL]);
        }
        foreach ($data as $key => $value) {
            $this->settings->set($key, $value);
        }
    }

    private function validate(array $data): void
    {
        $baseUrl = $this->effective($data, S::BASE_URL);
        if (!is_string($baseUrl) || PublicUrl::normalize($baseUrl) === null) {
            throw new InvalidConfigurationException(
                'base_url must be an absolute HTTP(S) URL without user information, query, or fragment.'
            );
        }

        $discoveryUrl = $this->effective($data, S::IDP_DISCOVERY_URL);
        if (!is_string($discoveryUrl) || !filter_var($discoveryUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidConfigurationException('idp_discovery_url must be a valid URL.');
        }

        $clientId = $this->effective($data, S::CLIENT_ID);
        if (!is_string($clientId) || $clientId === '') {
            throw new InvalidConfigurationException('client_id must be a non-empty string.');
        }

        $clientSecret = $this->effective($data, S::CLIENT_SECRET);
        if (!is_string($clientSecret) || $clientSecret === '') {
            throw new InvalidConfigurationException('client_secret must be a non-empty string.');
        }

        $scopes = $this->effective($data, S::SCOPES);
        if (!is_array($scopes) || $scopes === []) {
            throw new InvalidConfigurationException('scopes must be a non-empty array.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '') {
                throw new InvalidConfigurationException('scopes entries must be non-empty strings.');
            }
        }
        if (!in_array('openid', $scopes, true)) {
            throw new InvalidConfigurationException('scopes must include "openid".');
        }

        $roleClaim = $this->effective($data, S::ROLE_CLAIM);
        if (!is_string($roleClaim) || $roleClaim === '') {
            throw new InvalidConfigurationException('role_claim must be a non-empty string.');
        }

        $map = $this->effective($data, S::ROLES_MAP);
        if (!is_array($map)) {
            throw new InvalidConfigurationException('roles_map must be an array.');
        }
        foreach ($map as $key => $role) {
            if (!is_string($key) || $key === '') {
                throw new InvalidConfigurationException('roles_map keys must be non-empty strings.');
            }
            if (!in_array($role, self::ALLOWED_ROLES, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'roles_map[%s] = %s is not a recognised Omeka role.',
                    $key,
                    is_scalar($role) ? (string) $role : gettype($role)
                ));
            }
        }

        $roleDefault = $this->effective($data, S::ROLE_DEFAULT);
        if ($roleDefault !== null && !in_array($roleDefault, self::ALLOWED_ROLES, true)) {
            throw new InvalidConfigurationException('role_default is not a recognised Omeka role.');
        }

        $guardClaim = $this->effective($data, S::ACCESS_GUARD_CLAIM);
        if ($guardClaim !== null && !is_string($guardClaim)) {
            throw new InvalidConfigurationException('access_guard_claim must be a string or null.');
        }
        $guardValue = $this->effective($data, S::ACCESS_GUARD_VALUE);
        if ($guardValue !== null && !is_string($guardValue)) {
            throw new InvalidConfigurationException('access_guard_value must be a string or null.');
        }
        $hasGuardClaim = is_string($guardClaim) && trim($guardClaim) !== '';
        $hasGuardValue = is_string($guardValue) && trim($guardValue) !== '';
        if ($hasGuardClaim !== $hasGuardValue) {
            throw new InvalidConfigurationException(
                'access_guard_claim and access_guard_value must either both be set or both be null.'
            );
        }

        $hideLocalLogin = $this->effective($data, S::HIDE_LOCAL_LOGIN);
        if (!is_bool($hideLocalLogin)) {
            throw new InvalidConfigurationException('hide_local_login must be a boolean.');
        }

        $redirect = $this->effective($data, S::REDIRECT);
        if (!is_string($redirect) || !RedirectUrl::hasSafeSyntax($redirect)) {
            throw new InvalidConfigurationException(
                'redirect must be "home", a root-relative path, or an absolute HTTP(S) URL without user information.'
            );
        }

        $display = $this->effective($data, S::CLAIMS_DISPLAY);
        if (!is_array($display)) {
            throw new InvalidConfigurationException('claims_display must be an array.');
        }
        foreach ($display as $claim => $label) {
            if (!is_string($claim) || $claim === '') {
                throw new InvalidConfigurationException('claims_display keys must be non-empty claim names.');
            }
            if (!is_string($label)) {
                throw new InvalidConfigurationException('claims_display labels must be strings.');
            }
        }
    }

    /**
     * Resolve the value that would be in effect after this update without
     * forcing callers to resend unrelated settings.
     */
    private function effective(array $data, string $key)
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        return $this->settings->get($key, S::DEFAULTS[$key] ?? null);
    }
}
