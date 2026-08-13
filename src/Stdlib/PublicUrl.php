<?php
namespace Oidc\Stdlib;

/**
 * Validate and normalize the administrator-configured public Omeka URL.
 */
final class PublicUrl
{
    private function __construct()
    {
    }

    /**
     * Return canonical public URL components, or null for an unsafe URL.
     * The URL may include Omeka's installation subdirectory, but not query,
     * fragment, or user-information components.
     */
    public static function components(string $url): ?array
    {
        $url = trim($url);
        if ($url === ''
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || !preg_match('#^https?://#i', $url)
        ) {
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
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return null;
        }

        $authorityStart = strpos($url, '://') + 3;
        $authorityTail = substr($url, $authorityStart);
        $authority = substr($authorityTail, 0, strcspn($authorityTail, '/?#'));
        if ($authority === '' || str_contains($authority, '@')) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($host === '' || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && !str_starts_with($path, '/')) {
            return null;
        }
        $path = rtrim($path, '/');

        $displayHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $origin = $scheme . '://' . $displayHost
            . (($port !== null && $port !== $defaultPort) ? ':' . $port : '');

        return [
            'url' => $origin . $path,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'path' => $path,
        ];
    }

    public static function normalize(string $url): ?string
    {
        $components = self::components($url);
        return $components['url'] ?? null;
    }

    /**
     * Append a trusted root-relative application path to a valid base URL.
     */
    public static function appendPath(string $baseUrl, string $path): ?string
    {
        $baseUrl = self::normalize($baseUrl);
        if ($baseUrl === null
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
        ) {
            return null;
        }
        return $baseUrl . $path;
    }
}
