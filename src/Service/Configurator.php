<?php
namespace Oidc\Service;

use Oidc\Exception\InvalidConfigurationException;
use Oidc\Settings as S;
use Oidc\Stdlib\RedirectUrl;
use Omeka\Settings\Settings;

class Configurator
{
    private const SHORT_KEY_MAP = [
        'idp_discovery_url'   => S::IDP_DISCOVERY_URL,
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
        foreach ($data as $key => $value) {
            $this->settings->set($key, $value);
        }
    }

    private function validate(array $data): void
    {
        if (isset($data[S::IDP_DISCOVERY_URL])) {
            $url = $data[S::IDP_DISCOVERY_URL];
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidConfigurationException('idp_discovery_url must be a valid URL.');
            }
        }
        if (isset($data[S::CLIENT_ID]) && !is_string($data[S::CLIENT_ID])) {
            throw new InvalidConfigurationException('client_id must be a string.');
        }
        if (array_key_exists(S::CLIENT_SECRET, $data)) {
            if (!is_string($data[S::CLIENT_SECRET]) || $data[S::CLIENT_SECRET] === '') {
                throw new InvalidConfigurationException('client_secret must be a non-empty string.');
            }
        } elseif (!is_string($this->settings->get(S::CLIENT_SECRET)) || $this->settings->get(S::CLIENT_SECRET) === '') {
            throw new InvalidConfigurationException('client_secret is required.');
        }
        if (!array_key_exists(S::SCOPES, $data)) {
            throw new InvalidConfigurationException('scopes is required.');
        }
        $scopes = $data[S::SCOPES];
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
        if (!array_key_exists(S::ROLE_CLAIM, $data)) {
            throw new InvalidConfigurationException('role_claim is required.');
        }
        if (!is_string($data[S::ROLE_CLAIM]) || $data[S::ROLE_CLAIM] === '') {
            throw new InvalidConfigurationException('role_claim must be a non-empty string.');
        }
        if (array_key_exists(S::ROLES_MAP, $data)) {
            $map = $data[S::ROLES_MAP];
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
        }
        if (array_key_exists(S::ROLE_DEFAULT, $data) && $data[S::ROLE_DEFAULT] !== null) {
            if (!in_array($data[S::ROLE_DEFAULT], self::ALLOWED_ROLES, true)) {
                throw new InvalidConfigurationException('role_default is not a recognised Omeka role.');
            }
        }
        if (array_key_exists(S::ACCESS_GUARD_CLAIM, $data) && $data[S::ACCESS_GUARD_CLAIM] !== null
            && !is_string($data[S::ACCESS_GUARD_CLAIM])
        ) {
            throw new InvalidConfigurationException('access_guard_claim must be a string or null.');
        }
        if (array_key_exists(S::ACCESS_GUARD_VALUE, $data) && $data[S::ACCESS_GUARD_VALUE] !== null
            && !is_string($data[S::ACCESS_GUARD_VALUE])
        ) {
            throw new InvalidConfigurationException('access_guard_value must be a string or null.');
        }
        $guardClaim = array_key_exists(S::ACCESS_GUARD_CLAIM, $data)
            ? $data[S::ACCESS_GUARD_CLAIM]
            : $this->settings->get(S::ACCESS_GUARD_CLAIM);
        $guardValue = array_key_exists(S::ACCESS_GUARD_VALUE, $data)
            ? $data[S::ACCESS_GUARD_VALUE]
            : $this->settings->get(S::ACCESS_GUARD_VALUE);
        $hasGuardClaim = is_string($guardClaim) && trim($guardClaim) !== '';
        $hasGuardValue = is_string($guardValue) && trim($guardValue) !== '';
        if ($hasGuardClaim !== $hasGuardValue) {
            throw new InvalidConfigurationException(
                'access_guard_claim and access_guard_value must either both be set or both be null.'
            );
        }
        if (array_key_exists(S::HIDE_LOCAL_LOGIN, $data) && !is_bool($data[S::HIDE_LOCAL_LOGIN])) {
            throw new InvalidConfigurationException('hide_local_login must be a boolean.');
        }
        if (array_key_exists(S::REDIRECT, $data)) {
            if (!is_string($data[S::REDIRECT]) || !RedirectUrl::hasSafeSyntax($data[S::REDIRECT])) {
                throw new InvalidConfigurationException(
                    'redirect must be "home", a root-relative path, or an absolute HTTP(S) URL without user information.'
                );
            }
        }
        if (array_key_exists(S::CLAIMS_DISPLAY, $data)) {
            $display = $data[S::CLAIMS_DISPLAY];
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
    }
}
