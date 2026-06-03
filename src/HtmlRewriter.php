<?php

declare(strict_types=1);

namespace ArchiveSite;

final class HtmlRewriter
{
    /**
     * Rewrite asset references (src, href, srcset, inline style url()) to local paths.
     * $resolver receives an absolute URL and returns the replacement, or null to leave it unchanged.
     */
    public static function rewrite(string $html, string $baseUrl, callable $resolver): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $map = static function (string $ref) use ($baseUrl, $resolver): ?string {
            $abs = Url::resolve($baseUrl, $ref);
            if ($abs === null) {
                return null;
            }
            return $resolver($abs);
        };

        foreach ($xpath->query('//*[@src]') as $el) {
            /** @var \DOMElement $el */
            $new = $map($el->getAttribute('src'));
            if ($new !== null) {
                $el->setAttribute('src', $new);
            }
        }
        foreach ($xpath->query('//link[@href]') as $el) {
            /** @var \DOMElement $el */
            $new = $map($el->getAttribute('href'));
            if ($new !== null) {
                $el->setAttribute('href', $new);
            }
        }
        foreach ($xpath->query('//*[@srcset]') as $el) {
            /** @var \DOMElement $el */
            $el->setAttribute('srcset', self::rewriteSrcset($el->getAttribute('srcset'), $map));
        }
        foreach ($xpath->query('//*[@style]') as $el) {
            /** @var \DOMElement $el */
            $el->setAttribute('style', self::rewriteStyle($el->getAttribute('style'), $map));
        }
        foreach ($xpath->query('//style') as $el) {
            $el->textContent = self::rewriteStyle($el->textContent, $map);
        }

        $body = $dom->saveHTML();
        return $body === false ? $html : $body;
    }

    private static function rewriteSrcset(string $srcset, callable $map): string
    {
        $parts = [];
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = preg_split('/\s+/', $part, 2);
            $new = $map($bits[0]);
            $url = $new ?? $bits[0];
            $parts[] = isset($bits[1]) ? $url . ' ' . $bits[1] : $url;
        }
        return implode(', ', $parts);
    }

    private static function rewriteStyle(string $css, callable $map): string
    {
        return preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static function (array $m) use ($map): string {
                $new = $map(trim($m[2]));
                return 'url(' . $m[1] . ($new ?? trim($m[2])) . $m[1] . ')';
            },
            $css
        );
    }
}
