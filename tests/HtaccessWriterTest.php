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
        // Loop-free walk-up: one strip-rule per ancestor depth (no [N] restart, which loops in
        // .htaccess context), ending in a root index.html fallback.
        $this->assertStringNotContainsString('[N]', $c);
        $this->assertStringContainsString('RewriteRule ^(.+)/[^/]+/?$ /$1/index.html [L]', $c);
        $this->assertStringContainsString('RewriteRule ^ /index.html [L]', $c);
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
