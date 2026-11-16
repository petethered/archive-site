<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use ArchiveSite\ArchiveSite;
use ArchiveSite\Browser;

$folder = $argv[1] ?? null;
$url = $argv[2] ?? null;

if ($folder === null || $url === null) {
    fwrite(STDERR, "Usage: php archiveSite.php <folder> <url>\n");
    exit(1);
}

if (!preg_match('#^https?://#i', $url)) {
    fwrite(STDERR, "Error: <url> must start with http:// or https://\n");
    exit(1);
}

try {
    // CHROME_BIN overrides Chrome auto-detection; unset or empty means auto-detect.
    $chromeBinary = getenv(Browser::ENV_VAR);
    (new ArchiveSite(chromeBinary: $chromeBinary === false ? null : $chromeBinary))->run($folder, $url);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nFatal: " . $e->getMessage() . "\n");
    fwrite(STDERR, "If this is a Chrome launch error, see chrome_instructions.md\n");
    exit(1);
}
