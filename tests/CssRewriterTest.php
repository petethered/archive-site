<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\CssRewriter;
use PHPUnit\Framework\TestCase;

final class CssRewriterTest extends TestCase
{
    public function test_extract_urls_includes_import_and_url_excludes_google_and_data(): void
    {
        $css = <<<CSS
        @import "base.css";
        .a { background: url('img/bg.png'); }
        .b { background: url(data:image/png;base64,AAAA); }
        @font-face { src: url(https://fonts.gstatic.com/x.woff2); }
        CSS;

        $urls = CssRewriter::extractUrls($css, 'https://cdn.b.net/css/site.css');
        sort($urls);

        $this->assertSame([
            'https://cdn.b.net/css/base.css',
            'https://cdn.b.net/css/img/bg.png',
        ], $urls);
    }

    public function test_rewrite_replaces_with_resolver_output_and_leaves_nulls(): void
    {
        $css = ".a{background:url('img/bg.png')} @import 'base.css';";

        $resolver = static function (string $abs): ?string {
            return match ($abs) {
                'https://cdn.b.net/css/img/bg.png' => '../img/bg.png',
                'https://cdn.b.net/css/base.css'   => 'base.css',
                default => null,
            };
        };

        $out = CssRewriter::rewrite($css, 'https://cdn.b.net/css/site.css', $resolver);
        $this->assertStringContainsString("url('../img/bg.png')", $out);
        $this->assertStringContainsString("'base.css'", $out);
    }
}
