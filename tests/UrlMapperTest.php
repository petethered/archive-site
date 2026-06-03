<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\UrlMapper;
use PHPUnit\Framework\TestCase;

final class UrlMapperTest extends TestCase
{
    public function test_homepage_maps_to_index(): void
    {
        $this->assertSame('index.html', UrlMapper::pageUrlToPath('https://x.com/'));
        $this->assertSame('index.html', UrlMapper::pageUrlToPath('https://x.com'));
    }

    public function test_directory_page_maps_to_index_in_subdir(): void
    {
        $this->assertSame('privacy/index.html', UrlMapper::pageUrlToPath('https://x.com/privacy'));
        $this->assertSame('privacy/index.html', UrlMapper::pageUrlToPath('https://x.com/privacy/'));
    }

    public function test_html_page_kept_as_is(): void
    {
        $this->assertSame('about.html', UrlMapper::pageUrlToPath('https://x.com/about.html'));
    }

    public function test_query_string_page_is_not_archivable(): void
    {
        $this->assertFalse(UrlMapper::isArchivablePageUrl('https://x.com/search?q=faith'));
        $this->assertTrue(UrlMapper::isArchivablePageUrl('https://x.com/privacy'));
    }

    public function test_asset_path_under_static_by_host(): void
    {
        $this->assertSame(
            '__static/cdn.b.net/assets/app.js',
            UrlMapper::assetUrlToStaticPath('https://cdn.b.net/assets/app.js')
        );
    }

    public function test_asset_with_query_gets_hash_before_extension(): void
    {
        $p = UrlMapper::assetUrlToStaticPath('https://cdn.b.net/app.js?v=2');
        $this->assertMatchesRegularExpression('#^__static/cdn\.b\.net/app\.[0-9a-f]{8}\.js$#', $p);
        // deterministic: same URL -> same path
        $this->assertSame($p, UrlMapper::assetUrlToStaticPath('https://cdn.b.net/app.js?v=2'));
        // different query -> different file
        $this->assertNotSame($p, UrlMapper::assetUrlToStaticPath('https://cdn.b.net/app.js?v=3'));
    }

    public function test_asset_directory_url_gets_index_filename(): void
    {
        $this->assertSame(
            '__static/cdn.b.net/img/index',
            UrlMapper::assetUrlToStaticPath('https://cdn.b.net/img/')
        );
    }

    public function test_relative_href_from_nested_page(): void
    {
        $this->assertSame(
            '__static/h/app.js',
            UrlMapper::relativeHref('index.html', '__static/h/app.js')
        );
        $this->assertSame(
            '../__static/h/app.js',
            UrlMapper::relativeHref('privacy/index.html', '__static/h/app.js')
        );
        $this->assertSame(
            '../../__static/h/app.js',
            UrlMapper::relativeHref('a/b/index.html', '__static/h/app.js')
        );
    }
}
