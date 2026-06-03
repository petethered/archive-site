<?php

declare(strict_types=1);

namespace ArchiveSite;

final class Url
{
    /** Resolve a possibly-relative reference against a base URL. Returns null for non-http(s) or empty refs. */
    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref[0] === '#') {
            return null;
        }
        if (preg_match('#^(data|javascript|mailto|tel|blob|about):#i', $ref)) {
            return null;
        }
        if (preg_match('#^https?://#i', $ref)) {
            return self::stripFragment($ref);
        }

        $b = parse_url($base);
        if ($b === false || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $authority = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (str_starts_with($ref, '//')) {
            return self::stripFragment($b['scheme'] . ':' . $ref);
        }
        if (str_starts_with($ref, '/')) {
            return self::stripFragment($authority . self::normalizePath($ref));
        }

        $basePath = $b['path'] ?? '/';
        $slash = strrpos($basePath, '/');
        $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
        return self::stripFragment($authority . self::normalizePath($dir . $ref));
    }

    public static function stripFragment(string $url): string
    {
        $hash = strpos($url, '#');
        return $hash === false ? $url : substr($url, 0, $hash);
    }

    /** Collapse "." and ".." segments in the path portion (leaves any ?query/#frag tail intact). */
    private static function normalizePath(string $pathWithTail): string
    {
        $tail = '';
        foreach (['?', '#'] as $sep) {
            $pos = strpos($pathWithTail, $sep);
            if ($pos !== false) {
                $tail = substr($pathWithTail, $pos) . $tail;
                $pathWithTail = substr($pathWithTail, 0, $pos);
            }
        }
        $segments = explode('/', $pathWithTail);
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '.' || $seg === '') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $path = '/' . implode('/', $out);
        if (str_ends_with($pathWithTail, '/') && $path !== '/') {
            $path .= '/';
        }
        return $path . $tail;
    }
}
