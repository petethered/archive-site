<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\Crawler;
use PHPUnit\Framework\TestCase;

final class CrawlerTest extends TestCase
{
    public function test_registrable_domain_is_last_two_labels(): void
    {
        $this->assertSame('example.com', Crawler::registrableDomain('www.example.com'));
        $this->assertSame('example.com', Crawler::registrableDomain('example.com'));
    }

    public function test_in_scope_matches_registrable_domain(): void
    {
        $c = new Crawler('https://example.com/');
        $this->assertTrue($c->inScope('https://www.example.com/x'));
        $this->assertTrue($c->inScope('https://blog.example.com/y'));
        $this->assertFalse($c->inScope('https://cdn.example.net/z'));
        $this->assertFalse($c->inScope('ftp://example.com/z'));
    }

    public function test_extract_links_filters_normalizes_and_dedupes(): void
    {
        $html = <<<HTML
        <html><body>
          <a href="/privacy/">Privacy</a>
          <a href="/privacy">Privacy again</a>
          <a href="about.html#team">About</a>
          <a href="https://external.com/x">External</a>
          <a href="/search?q=faith">Search</a>
          <a href="mailto:a@b.com">Mail</a>
        </body></html>
        HTML;

        $c = new Crawler('https://example.com/');
        $links = $c->extractLinks($html, 'https://example.com/');
        sort($links);

        $this->assertSame([
            'https://example.com/about.html',
            'https://example.com/privacy',
        ], $links);
    }
}
