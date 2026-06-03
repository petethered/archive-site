<?php

declare(strict_types=1);

namespace ArchiveSite;

final class HtaccessWriter
{
    public static function contents(): string
    {
        return <<<'HTACCESS'
        RewriteEngine On
        RewriteBase /

        # 1. Real file or directory -> serve it
        RewriteCond %{REQUEST_FILENAME} -f [OR]
        RewriteCond %{REQUEST_FILENAME} -d
        RewriteRule ^ - [L]

        # 2. Current path has an index.html -> serve it (internal rewrite, URL unchanged)
        RewriteCond %{DOCUMENT_ROOT}/$1/index.html -f
        RewriteRule ^(.+?)/?[^/]*$ /$1/index.html [L]

        # 3. No index.html here -> strip last segment and re-loop
        RewriteRule ^(.+)/[^/]+/?$ /$1 [N]

        # 4. Fell through to root -> serve root index.html
        RewriteRule ^ /index.html [L]

        HTACCESS;
    }

    public static function write(string $webroot): void
    {
        file_put_contents(rtrim($webroot, '/') . '/.htaccess', self::contents());
    }
}
