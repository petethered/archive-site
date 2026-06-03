<?php

declare(strict_types=1);

namespace ArchiveSite;

final class CssRewriter
{
    /** Return de-duplicated absolute URLs referenced (url() + @import) by the CSS, excluding data: and Google Fonts. */
    public static function extractUrls(string $css, string $baseUrl): array
    {
        $found = [];
        foreach (self::rawRefs($css) as $ref) {
            $abs = Url::resolve($baseUrl, $ref);
            if ($abs === null) {
                continue;
            }
            $host = parse_url($abs, PHP_URL_HOST);
            if ($host !== null && in_array(strtolower($host), AssetCollector::GOOGLE_FONT_HOSTS, true)) {
                continue;
            }
            $found[$abs] = true;
        }
        return array_keys($found);
    }

    /**
     * Rewrite url() and @import references. $resolver receives the absolute URL and returns the
     * replacement string, or null to leave the original reference unchanged.
     */
    public static function rewrite(string $css, string $baseUrl, callable $resolver): string
    {
        $replace = static function (string $ref) use ($baseUrl, $resolver): string {
            $abs = Url::resolve($baseUrl, $ref);
            if ($abs === null) {
                return $ref;
            }
            $new = $resolver($abs);
            return $new ?? $ref;
        };

        // url(...) form
        $css = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static function (array $m) use ($replace): string {
                $quote = $m[1];
                return 'url(' . $quote . $replace(trim($m[2])) . $quote . ')';
            },
            $css
        );

        // @import "..." / @import '...' form (without url())
        $css = preg_replace_callback(
            '/@import\s+([\'"])([^\'"]+)\1/i',
            static function (array $m) use ($replace): string {
                return '@import ' . $m[1] . $replace(trim($m[2])) . $m[1];
            },
            $css
        );

        return $css;
    }

    /** @return string[] */
    private static function rawRefs(string $css): array
    {
        $refs = [];
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $m)) {
            foreach ($m[2] as $u) {
                $refs[] = trim($u);
            }
        }
        if (preg_match_all('/@import\s+([\'"])([^\'"]+)\1/i', $css, $m)) {
            foreach ($m[2] as $u) {
                $refs[] = trim($u);
            }
        }
        return array_filter($refs, static fn (string $u): bool => $u !== '' && !str_starts_with($u, 'data:'));
    }
}
