<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\Browser;
use PHPUnit\Framework\TestCase;

final class BrowserTest extends TestCase
{
    public function test_builds_command_with_required_flags(): void
    {
        $cmd = Browser::buildArgs('/path/to/chrome', 'https://x.com/', '/tmp/profileXYZ');

        $this->assertSame('/path/to/chrome', $cmd[0]);
        $this->assertContains('--headless=new', $cmd);
        $this->assertContains('--no-sandbox', $cmd);
        $this->assertContains('--disable-gpu', $cmd);
        $this->assertContains('--user-data-dir=/tmp/profileXYZ', $cmd);
        $this->assertContains('--dump-dom', $cmd);
        $this->assertContains('https://x.com/', $cmd);
        // URL must be the last argument
        $this->assertSame('https://x.com/', $cmd[array_key_last($cmd)]);
    }

    public function test_detects_known_binary_paths(): void
    {
        $existing = tempnam(sys_get_temp_dir(), 'chromebin');
        chmod($existing, 0755);
        $this->assertSame($existing, Browser::detectBinary([$existing, '/nope/here']));
        $this->assertNull(Browser::detectBinary(['/nope/a', '/nope/b']));
        unlink($existing);
    }
}
