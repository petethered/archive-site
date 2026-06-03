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

    public function test_rewrites_srcset(): void
    {
        $html = '<html><body><img src="/a.png" srcset="/a.png 1x, /a2.png 2x"></body></html>';
        $resolver = static fn (string $abs): string => '__local/' . basename(parse_url($abs, PHP_URL_PATH));
        $out = HtmlRewriter::rewrite($html, 'https://x.com/', $resolver);
        $this->assertStringContainsString('__local/a.png 1x', $out);
        $this->assertStringContainsString('__local/a2.png 2x', $out);
    }
}
