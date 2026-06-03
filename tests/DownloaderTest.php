<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\Downloader;
use PHPUnit\Framework\TestCase;

final class DownloaderTest extends TestCase
{
    public function test_downloads_to_destination_creating_dirs(): void
    {
        $src = 'file://' . realpath(__DIR__ . '/fixtures/sample.txt');
        $dest = sys_get_temp_dir() . '/dl_' . bin2hex(random_bytes(4)) . '/nested/out.txt';

        $ok = (new Downloader())->download($src, $dest);

        $this->assertTrue($ok);
        $this->assertFileExists($dest);
        $this->assertSame('hello-archive', trim(file_get_contents($dest)));
    }

    public function test_returns_false_on_missing_source(): void
    {
        $src = 'file:///definitely/not/here_' . bin2hex(random_bytes(4)) . '.txt';
        $dest = sys_get_temp_dir() . '/dl_' . bin2hex(random_bytes(4)) . '.txt';
        $this->assertFalse((new Downloader())->download($src, $dest));
    }
}
