<?php
namespace HaloAudit;

final class Url
{
    public static function normalise(string $input): string
    {
        $url = trim($input);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }
        $out = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= str_replace(' ', '%20', $parts['path'] ?? '');
        if (isset($parts['query'])) {
            $out .= '?' . str_replace(' ', '%20', $parts['query']);
        }
        return $out;
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    public static function origin(string $url): string
    {
        $p = parse_url($url) ?: [];
        $out = strtolower($p['scheme'] ?? 'https') . '://' . strtolower($p['host'] ?? '');
        return isset($p['port']) ? $out . ':' . $p['port'] : $out;
    }

    public static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function sameSite(string $a, string $b): bool
    {
        $strip = static fn(string $u): string => (string) preg_replace('/^www\./', '', self::host($u));
        return $strip($a) !== '' && $strip($a) === $strip($b);
    }

    public static function sameUrl(string $a, string $b): bool
    {
        $key = static function (string $u): string {
            $p = parse_url($u) ?: [];
            return strtolower($p['scheme'] ?? '') . '://' . strtolower($p['host'] ?? '')
                . rtrim($p['path'] ?? '', '/') . '?' . ($p['query'] ?? '');
        };
        return $key($a) === $key($b);
    }

    public static function resolve(string $base, string $rel): string
    {
        $rel = trim($rel);
        if ($rel === '') {
            return $base;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rel)) {
            return $rel;
        }
        $b = parse_url($base) ?: [];
        $scheme = strtolower($b['scheme'] ?? 'https');
        $authority = strtolower($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        $basePath = isset($b['path']) && $b['path'] !== '' ? $b['path'] : '/';

        if (strpos($rel, '//') === 0) {
            return $scheme . ':' . $rel;
        }
        if ($rel[0] === '?') {
            return $scheme . '://' . $authority . $basePath . $rel;
        }
        if ($rel[0] === '#') {
            return (string) strtok($base, '#') . $rel;
        }

        $path = $rel[0] === '/' ? $rel : preg_replace('#/[^/]*$#', '/', $basePath) . $rel;

        $suffix = '';
        if (preg_match('/[?#].*$/', $path, $m)) {
            $suffix = $m[0];
            $path = substr($path, 0, -strlen($suffix));
        }

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }

        return $scheme . '://' . $authority . implode('/', $out) . $suffix;
    }
}
