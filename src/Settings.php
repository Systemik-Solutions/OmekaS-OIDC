<?php
namespace Oidc;

final class Settings
{
    const IDP_DISCOVERY_URL   = 'oidc_idp_discovery_url';
    const CLIENT_ID           = 'oidc_client_id';
    const CLIENT_SECRET       = 'oidc_client_secret';
    const SCOPES              = 'oidc_scopes';
    const ROLE_CLAIM          = 'oidc_role_claim';
    const ROLES_MAP           = 'oidc_roles_map';
    const ROLE_DEFAULT        = 'oidc_role_default';
    const ACCESS_GUARD_CLAIM  = 'oidc_access_guard_claim';
    const ACCESS_GUARD_VALUE  = 'oidc_access_guard_value';
    const HIDE_LOCAL_LOGIN    = 'oidc_hide_local_login';
    const REDIRECT            = 'oidc_redirect';
    const CLAIMS_DISPLAY      = 'oidc_claims_display';

    const KEYS = [
        self::IDP_DISCOVERY_URL,
        self::CLIENT_ID,
        self::CLIENT_SECRET,
        self::SCOPES,
        self::ROLE_CLAIM,
        self::ROLES_MAP,
        self::ROLE_DEFAULT,
        self::ACCESS_GUARD_CLAIM,
        self::ACCESS_GUARD_VALUE,
        self::HIDE_LOCAL_LOGIN,
        self::REDIRECT,
        self::CLAIMS_DISPLAY,
    ];

    const DEFAULTS = [
        self::IDP_DISCOVERY_URL  => 'https://cilogon.org/.well-known/openid-configuration', 
        self::SCOPES             => ['openid', 'email', 'profile', 'org.cilogon.userinfo'],
        self::ROLE_CLAIM         => 'isMemberOf',
        self::ROLE_DEFAULT       => null, 
        self::ROLES_MAP          => [],
        self::ACCESS_GUARD_CLAIM => null,
        self::ACCESS_GUARD_VALUE => null,
        self::HIDE_LOCAL_LOGIN   => true,
        self::REDIRECT           => 'home',
        self::CLAIMS_DISPLAY     => [],
    ];

    private function __construct()
    {
    }
}
