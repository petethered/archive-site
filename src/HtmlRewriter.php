<?php

declare(strict_types=1);

namespace ArchiveSite;

final class HtmlRewriter
{
    /**
     * Rewrite asset references (src, link href, srcset, inline style url()) to local paths.
     * $resolver receives an absolute URL and returns the replacement, or null to leave it unchanged.
     *
     * If $linkResolver is provided, anchor (<a href>) targets are also rewritten: it receives the
     * resolved absolute URL and returns a replacement href (e.g. a root-relative path), or null to
     * leave the link unchanged. Any fragment on the original href is preserved.
     */
    public static function rewrite(
        string $html,
        string $baseUrl,
        callable $resolver,
        ?callable $linkResolver = null
    ): string {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // Encode non-ASCII to numeric entities so libxml (which assumes Latin-1) parses UTF-8
        // intact; decoded back after save. This avoids injecting an xml processing instruction.
        $dom->loadHTML(self::encodeUtf8($html), LIBXML_NOERROR);
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
        if ($linkResolver !== null) {
            foreach ($xpath->query('//a[@href]') as $el) {
                /** @var \DOMElement $el */
                $raw = $el->getAttribute('href');
                $hash = strpos($raw, '#');
                $fragment = $hash === false ? '' : substr($raw, $hash);
                $abs = Url::resolve($baseUrl, $raw);
                if ($abs === null) {
                    continue; // fragment-only, mailto:, etc. -> leave as-is
                }
                $new = $linkResolver($abs);
                if ($new !== null) {
                    $el->setAttribute('href', $new . $fragment);
                }
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
        return $body === false ? $html : self::decodeUtf8($body);
    }

    private const ENTITY_MAP = [0x80, 0x10FFFF, 0, 0x10FFFF];

    private static function encodeUtf8(string $html): string
    {
        return mb_encode_numericentity($html, self::ENTITY_MAP, 'UTF-8');
    }

    private static function decodeUtf8(string $html): string
    {
        return mb_decode_numericentity($html, self::ENTITY_MAP, 'UTF-8');
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
