<?php

declare(strict_types=1);

namespace ArchiveSite;

final class UrlMapper
{
    public static function isArchivablePageUrl(string $url): bool
    {
        $parts = parse_url($url);
        return $parts !== false && empty($parts['query']);
    }

    /** Map a page URL to a webroot-relative file path. */
    public static function pageUrlToPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $path = trim((string) $path, '/');
        if ($path === '') {
            return 'index.html';
        }
        if (str_ends_with(strtolower($path), '.html')) {
            return $path;
        }
        return $path . '/index.html';
    }

    /**
     * Resolution base for relative references on a page. A page mapped to `blog/index.html`
     * is served at `/blog/`, so relative refs must resolve against the directory (with trailing
     * slash), not the slash-stripped page URL. Returns e.g. `https://site.com/blog/`.
     */
    public static function pageBaseUrl(string $pageUrl): string
    {
        $parts = parse_url($pageUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $rel = self::pageUrlToPath($pageUrl);
        $dir = trim(str_replace('\\', '/', dirname($rel)), '/');
        $base = $scheme . '://' . $host . $port . '/';
        if ($dir !== '' && $dir !== '.') {
            $base .= $dir . '/';
        }
        return $base;
    }

    /** Map an asset URL to a webroot-relative path under __static/<host>/. */
    public static function assetUrlToStaticPath(string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? 'unknown-host';
        // Collapse "." and ".." segments so a malicious/sloppy URL cannot escape __static/<host>/.
        $path = self::collapseDotSegments($parts['path'] ?? '');
        $path = ltrim($path, '/');
        if ($path === '' || str_ends_with($path, '/')) {
            $path .= 'index';
        }

        if (!empty($parts['query'])) {
            $hash = substr(sha1($url), 0, 8);
            $dot = strrpos($path, '.');
            // only treat as extension if the dot is in the final path segment
            if ($dot !== false && $dot > (int) strrpos($path, '/')) {
                $path = substr($path, 0, $dot) . '.' . $hash . substr($path, $dot);
            } else {
                $path .= '.' . $hash;
            }
        }

        return '__static/' . $host . '/' . $path;
    }

    /** Relative href from one webroot-relative file to another webroot-relative target. */
    public static function relativeHref(string $fromFile, string $toTarget): string
    {
        $fromDir = trim(str_replace('\\', '/', dirname($fromFile)), '/');
        if ($fromDir === '' || $fromDir === '.') {
            return $toTarget;
        }
        $depth = count(explode('/', $fromDir));
        return str_repeat('../', $depth) . $toTarget;
    }

    /** Collapse "." and ".." segments in a URL path; ".." can never rise above the root. */
    private static function collapseDotSegments(string $path): string
    {
        $leadingSlash = str_starts_with($path, '/');
        $trailingSlash = str_ends_with($path, '/') && $path !== '';
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $result = implode('/', $out);
        if ($leadingSlash) {
            $result = '/' . $result;
        }
        if ($trailingSlash && !str_ends_with($result, '/')) {
            $result .= '/';
        }
        return $result;
    }
}
