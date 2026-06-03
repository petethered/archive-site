<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\HtmlRewriter;
use PHPUnit\Framework\TestCase;

final class HtmlRewriterTest extends TestCase
{
    public function test_rewrites_src_and_href_but_leaves_resolver_nulls(): void
    {
        $html = <<<HTML
        <html><head>
          <link rel="stylesheet" href="/css/site.css">
          <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto">
        </head><body>
          <img src="/img/logo.png">
        </body></html>
        HTML;

        $resolver = static function (string $abs): ?string {
            return match ($abs) {
                'https://x.com/css/site.css' => '__static/x.com/css/site.css',
                'https://x.com/img/logo.png' => '__static/x.com/img/logo.png',
                default => null, // Google Fonts -> left as-is
            };
        };

        $out = HtmlRewriter::rewrite($html, 'https://x.com/', $resolver);

        $this->assertStringContainsString('href="__static/x.com/css/site.css"', $out);
        $this->assertStringContainsString('src="__static/x.com/img/logo.png"', $out);
        $this->assertStringContainsString('https://fonts.googleapis.com/css?family=Roboto', $out);
    }

    public function test_no_xml_pi_leak_and_utf8_content_preserved(): void
    {
        $html = '<!DOCTYPE html><html><head></head><body><p>Café &amp; thé</p>'
            . '<img src="/a.png"></body></html>';
        $out = HtmlRewriter::rewrite($html, 'https://x.com/', static fn (string $a): string => '__static/x/a.png');

        // No stray <?xml ...> processing instruction in the output.
        $this->assertStringNotContainsString('<?xml', $out);
        // Content is preserved (UTF-8 not corrupted); accents may be HTML entities, which
        // decode back to the original text and render identically.
        $decoded = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Café & thé', $decoded);
        $this->assertStringContainsString('src="__static/x/a.png"', $out);
    }

    public function test_rewrites_internal_links_to_root_relative_via_link_resolver(): void
    {
        $html = '<html><body>'
            . '<a href="/aboutus">About</a>'
            . '<a href="/aboutus/#team">About team</a>'
            . '<a href="https://external.com/x">External</a>'
            . '<a href="#section">In-page</a>'
            . '</body></html>';

        $assetResolver = static fn (string $a): ?string => null;
        // Simulate the orchestrator's link resolver: in-scope -> root-relative, else null.
        $linkResolver = static function (string $abs): ?string {
            if (!str_starts_with($abs, 'https://x.com/')) {
                return null; // external -> leave absolute
            }
            $path = parse_url($abs, PHP_URL_PATH) ?: '/';
            return $path === '/' ? '/' : (rtrim($path, '/') ?: '/');
        };

        $out = HtmlRewriter::rewrite($html, 'https://x.com/', $assetResolver, $linkResolver);

        $this->assertStringContainsString('href="/aboutus"', $out);
        $this->assertStringContainsString('href="/aboutus#team"', $out); // fragment preserved
        $this->assertStringContainsString('href="https://external.com/x"', $out); // external untouched
        $this->assertStringContainsString('href="#section"', $out); // in-page anchor untouched
    }

    public function test_rewrites_srcset(): void
    {
        $html = '<html><body><img src="/a.png" srcset="/a.png 1x, /a2.png 2x"></body></html>';
        $resolver = static fn (string $abs): string => '__local/' . basename(parse_url($abs, PHP_URL_PATH));
        $out = HtmlRewriter::rewrite($html, 'https://x.com/', $resolver);
        $this->assertStringContainsString('__local/a.png 1x', $out);
        $this->assertStringContainsString('__local/a2.png 2x', $out);
    }
}
