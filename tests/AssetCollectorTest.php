<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\AssetCollector;
use PHPUnit\Framework\TestCase;

final class AssetCollectorTest extends TestCase
{
    public function test_collects_assets_and_excludes_google_fonts(): void
    {
        $html = <<<HTML
        <html><head>
          <link rel="stylesheet" href="/css/site.css">
          <link rel="icon" href="/favicon.ico">
          <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto">
          <script src="https://cdn.b.net/app.js?v=2"></script>
          <style>.h{background:url('/img/bg.png')}</style>
        </head><body>
          <img src="/img/logo.png" srcset="/img/logo@2x.png 2x, /img/logo@3x.png 3x">
          <div style="background: url(/img/hero.jpg)"></div>
          <a href="/privacy">link, not an asset</a>
        </body></html>
        HTML;

        $assets = AssetCollector::collect($html, 'https://x.com/');
        sort($assets);

        $this->assertSame([
            'https://cdn.b.net/app.js?v=2',
            'https://x.com/css/site.css',
            'https://x.com/favicon.ico',
            'https://x.com/img/bg.png',
            'https://x.com/img/hero.jpg',
            'https://x.com/img/logo.png',
            'https://x.com/img/logo@2x.png',
            'https://x.com/img/logo@3x.png',
        ], $assets);

        $this->assertNotContains('https://fonts.googleapis.com/css?family=Roboto', $assets);
    }
}
