<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function test_absolute_url_is_returned_without_fragment(): void
    {
        $this->assertSame(
            'https://x.com/a/b',
            Url::resolve('https://x.com/', 'https://x.com/a/b#frag')
        );
    }

    public function test_root_relative(): void
    {
        $this->assertSame(
            'https://x.com/privacy',
            Url::resolve('https://x.com/about/', '/privacy')
        );
    }

    public function test_protocol_relative(): void
    {
        $this->assertSame(
            'https://cdn.com/app.js',
            Url::resolve('https://x.com/', '//cdn.com/app.js')
        );
    }

    public function test_path_relative_with_dot_segments(): void
    {
        $this->assertSame(
            'https://x.com/app.js',
            Url::resolve('https://x.com/about/team/', '../../app.js')
        );
    }

    public function test_query_is_preserved(): void
    {
        $this->assertSame(
            'https://x.com/app.js?v=2',
            Url::resolve('https://x.com/', 'app.js?v=2')
        );
    }

    public function test_non_http_schemes_are_ignored(): void
    {
        $this->assertNull(Url::resolve('https://x.com/', 'mailto:a@b.com'));
        $this->assertNull(Url::resolve('https://x.com/', 'data:image/png;base64,AAAA'));
        $this->assertNull(Url::resolve('https://x.com/', '#section'));
        $this->assertNull(Url::resolve('https://x.com/', ''));
    }
}
