<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\Manifest;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    public function test_accumulates_and_serializes(): void
    {
        $m = new Manifest('https://x.com', '2026-06-03T00:00:00Z');
        $m->addPage('https://x.com/privacy', 'privacy/index.html', 'fetched');
        $m->addAsset('https://cdn.b.net/app.js', 'cdn.b.net', '__static/cdn.b.net/app.js', 'downloaded');
        $m->addSkipped('https://x.com/search?q=1', 'query-string');
        $m->addError('https://x.com/broken', 'timeout');
        $m->finish('2026-06-03T00:01:00Z');

        $data = json_decode($m->toJson(), true);

        $this->assertSame('https://x.com', $data['source']);
        $this->assertSame('2026-06-03T00:00:00Z', $data['startedAt']);
        $this->assertSame('2026-06-03T00:01:00Z', $data['finishedAt']);
        $this->assertSame('privacy/index.html', $data['pages'][0]['path']);
        $this->assertSame('downloaded', $data['assets'][0]['status']);
        $this->assertSame('query-string', $data['skipped'][0]['reason']);
        $this->assertSame('timeout', $data['errors'][0]['reason']);
    }
}
