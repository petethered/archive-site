<?php

declare(strict_types=1);

namespace ArchiveSite;

final class AssetCollector
{
    public const GOOGLE_FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

    /** Return de-duplicated absolute asset URLs referenced by the HTML (excludes Google Fonts). */
    public static function collect(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $found = [];
        $add = static function (?string $abs) use (&$found): void {
            if ($abs === null) {
                return;
            }
            $host = parse_url($abs, PHP_URL_HOST);
            if ($host !== null && in_array(strtolower($host), self::GOOGLE_FONT_HOSTS, true)) {
                return;
            }
            $found[$abs] = true;
        };

        foreach ($xpath->query('//*[@src]') as $el) {
            /** @var \DOMElement $el */
            $add(Url::resolve($baseUrl, $el->getAttribute('src')));
        }
        foreach ($xpath->query('//link[@href]') as $el) {
            /** @var \DOMElement $el */
            $add(Url::resolve($baseUrl, $el->getAttribute('href')));
        }
        foreach ($xpath->query('//*[@srcset]') as $el) {
            /** @var \DOMElement $el */
            foreach (self::parseSrcset($el->getAttribute('srcset')) as $candidate) {
                $add(Url::resolve($baseUrl, $candidate));
            }
        }
        foreach ($xpath->query('//*[@style]') as $el) {
            /** @var \DOMElement $el */
            foreach (self::cssUrls($el->getAttribute('style')) as $u) {
                $add(Url::resolve($baseUrl, $u));
            }
        }
        foreach ($xpath->query('//style') as $el) {
            foreach (self::cssUrls($el->textContent) as $u) {
                $add(Url::resolve($baseUrl, $u));
            }
        }

        return array_keys($found);
    }

    /** @return string[] */
    public static function parseSrcset(string $srcset): array
    {
        $out = [];
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $url = preg_split('/\s+/', $part)[0] ?? '';
            if ($url !== '') {
                $out[] = $url;
            }
        }
        return $out;
    }

    /** @return string[] Raw url() targets found in a CSS snippet. */
    public static function cssUrls(string $css): array
    {
        $out = [];
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $m)) {
            foreach ($m[2] as $u) {
                $u = trim($u);
                if ($u !== '' && !str_starts_with($u, 'data:')) {
                    $out[] = $u;
                }
            }
        }
        return $out;
    }
}
