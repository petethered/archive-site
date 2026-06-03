<?php

declare(strict_types=1);

namespace ArchiveSite;

final class HtaccessWriter
{
    /** How many ancestor levels the walk-up rules cover (well beyond any realistic site depth). */
    public const MAX_DEPTH = 15;

    public static function contents(): string
    {
        $lines = [
            'RewriteEngine On',
            'RewriteBase /',
            '',
            '# 1. Real file or directory -> serve it',
            'RewriteCond %{REQUEST_FILENAME} -f [OR]',
            'RewriteCond %{REQUEST_FILENAME} -d',
            'RewriteRule ^ - [L]',
            '',
            '# 2. Serve the index.html of the nearest existing ancestor directory.',
            '#    Each rule strips one more trailing segment than the last; the first ancestor that',
            '#    has an index.html wins (nearest ancestor). Loop-free: no restart flag, which would',
            '#    grow the URI under RewriteBase and trigger a 500 in .htaccess context.',
        ];

        for ($depth = 1; $depth <= self::MAX_DEPTH; $depth++) {
            // Pattern matches a path with $depth trailing segments after the captured ancestor.
            $tail = str_repeat('/[^/]+', $depth);
            $lines[] = 'RewriteCond %{DOCUMENT_ROOT}/$1/index.html -f';
            $lines[] = sprintf('RewriteRule ^(.+)%s/?$ /$1/index.html [L]', $tail);
        }

        $lines[] = '';
        $lines[] = '# 3. No ancestor matched -> serve the root index.html';
        $lines[] = 'RewriteRule ^ /index.html [L]';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public static function write(string $webroot): void
    {
        file_put_contents(rtrim($webroot, '/') . '/.htaccess', self::contents());
    }
}
