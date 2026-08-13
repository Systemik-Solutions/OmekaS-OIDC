<?php
namespace Oidc\Stdlib;

/**
 * Validate and normalize post-login redirect destinations.
 */
final class RedirectUrl
{
    private function __construct()
    {
    }

    /**
     * Check destination syntax without requiring the current request origin.
     *
     * Empty and "home" values are valid configuration fallbacks. Other
     * destinations must be root-relative paths or absolute HTTP(S) URLs
     * without ambiguous authority syntax or user information.
     */
    public static function hasSafeSyntax(string $url): bool
    {
        if ($url === '' || $url === 'home') {
            return true;
        }
        if (self::hasUnsafeCharacters($url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        return self::parseAbsolute($url) !== null;
    }

    /**
     * Return a canonical same-origin URL, or null for an unsafe destination.
     */
    public static function normalizeForOrigin(
        string $url,
        string $currentScheme,
        string $currentHost,
        ?int $currentPort = null,
        string $baseUrl = ''
    ): ?string {
        if (!self::hasSafeSyntax($url) || $url === '' || $url === 'home') {
            return null;
        }

        $currentScheme = strtolower($currentScheme);
        $normalizedCurrentHost = self::normalizeHost($currentHost);
        if (!in_array($currentScheme, ['http', 'https'], true) || $normalizedCurrentHost === '') {
            return null;
        }

        $origin = self::buildOrigin($currentScheme, $currentHost, $currentPort);
        if (str_starts_with($url, '/')) {
            return $origin . rtrim($baseUrl, '/') . $url;
        }

        $parts = self::parseAbsolute($url);
        if ($parts === null
            || strtolower($parts['scheme']) !== $currentScheme
            || self::normalizeHost($parts['host']) !== $normalizedCurrentHost
            || self::effectivePort(strtolower($parts['scheme']), $parts['port'] ?? null)
                !== self::effectivePort($currentScheme, $currentPort)
        ) {
            return null;
        }

        // Rebuild from parsed components and the trusted request origin. Never
        // return the caller's raw absolute URL, because PHP and browsers may
        // parse ambiguous authority strings differently.
        $normalized = $origin . ($parts['path'] ?? '');
        if (array_key_exists('query', $parts)) {
            $normalized .= '?' . $parts['query'];
        }
        if (array_key_exists('fragment', $parts)) {
            $normalized .= '#' . $parts['fragment'];
        }
        return $normalized;
    }

    private static function parseAbsolute(string $url): ?array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError $e) {
            return null;
        }
        if (!is_array($parts)
            || empty($parts['scheme'])
            || empty($parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            return null;
        }

        $authorityStart = strpos($url, '://') + 3;
        $authorityTail = substr($url, $authorityStart);
        $authority = substr($authorityTail, 0, strcspn($authorityTail, '/?#'));
        if ($authority === '' || str_contains($authority, '@')) {
            return null;
        }
        return $parts;
    }

    private static function hasUnsafeCharacters(string $url): bool
    {
        return str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1;
    }

    private static function effectivePort(string $scheme, ?int $port): ?int
    {
        if ($port !== null) {
            return $port;
        }
        return $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
    }

    private static function normalizeHost(string $host): string
    {
        return strtolower(trim($host, '[]'));
    }

    private static function buildOrigin(string $scheme, string $host, ?int $port): string
    {
        $host = trim($host, '[]');
        if (str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . (($port !== null && $port !== $defaultPort) ? ':' . $port : '');
    }
}
