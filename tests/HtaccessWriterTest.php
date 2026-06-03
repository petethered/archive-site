<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\HtaccessWriter;
use PHPUnit\Framework\TestCase;

final class HtaccessWriterTest extends TestCase
{
    public function test_contents_has_walkup_rules(): void
    {
        $c = HtaccessWriter::contents();
        $this->assertStringContainsString('RewriteEngine On', $c);
        $this->assertStringContainsString('%{DOCUMENT_ROOT}/$1/index.html -f', $c);
        $this->assertStringContainsString('[N]', $c);
        $this->assertStringContainsString('/index.html', $c);
    }

    public function test_write_creates_file_in_webroot(): void
    {
        $dir = sys_get_temp_dir() . '/htacc_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        HtaccessWriter::write($dir);
        $this->assertFileExists($dir . '/.htaccess');
        $this->assertStringContainsString('RewriteEngine On', file_get_contents($dir . '/.htaccess'));
    }
}
