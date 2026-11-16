<?php

declare(strict_types=1);

namespace ArchiveSite;

final class ArchiveSite
{
    public function __construct(
        private readonly int $maxPages = 500,
        private readonly ?string $chromeBinary = null,
    ) {
    }

    public function run(string $folder, string $seedUrl): void
    {
        $t0 = microtime(true);
        $folder = rtrim($folder, '/');
        $webroot = $folder . '/webroot';
        $static = $webroot . '/__static';

        $crawler = new Crawler($seedUrl);
        // Resolve Chrome before creating any folders so a missing binary fails fast.
        $browser = new Browser($this->chromeBinary);
        $this->out('archiveSite — archiving ' . $seedUrl);
        $this->out('  output folder : ' . $folder);
        $this->out('  chrome binary : ' . $browser->binary());
        $this->out('  scope domain  : ' . Crawler::registrableDomain((string) parse_url($seedUrl, PHP_URL_HOST)));
        $this->out('  max pages     : ' . $this->maxPages);
        $this->out('');

        $this->out('[1/6] Scaffolding directories');
        $this->mkdir($folder);
        $this->mkdir($webroot);
        $this->mkdir($static);
        $this->out('  created ' . $webroot . '/ and __static/');
        $this->out('');

        $startedAt = gmdate('c');
        $manifest = new Manifest($seedUrl, $startedAt);

        // 2. Crawl (render + discover) ----------------------------------------
        $this->out('[2/6] Crawling & rendering pages (headless Chrome)');
        $skipped = [];   // url => true, so each skipped link is logged once
        $result = $crawler->crawl(
            $seedUrl,
            $browser,
            $this->maxPages,
            function (string $url, string $reason) use ($manifest): void {
                $this->err('      ! render error ' . $url . ': ' . $reason);
                $manifest->addError($url, $reason);
            },
            function (string $event, array $d): void {
                if ($event === 'rendering') {
                    $this->out(sprintf('  [page %d] rendering %s …', $d['index'], $d['url']));
                } elseif ($event === 'rendered') {
                    $this->out(sprintf(
                        '            ok — %s, %d new link(s), %d queued',
                        $this->humanBytes($d['bytes']),
                        $d['newLinks'],
                        $d['queued']
                    ));
                }
            },
            function (string $url, string $reason) use ($manifest, &$skipped): void {
                if (isset($skipped[$url])) {
                    return; // the same link usually appears on many pages; record it once
                }
                $skipped[$url] = true;
                $manifest->addSkipped($url, $reason);
                $this->out('            SKIP (' . $reason . ') ' . $url);
            }
        );
        $this->out(sprintf(
            '  rendered %d page(s), skipped %d link(s)',
            count($result['order']),
            count($skipped)
        ));
        if ($result['capped']) {
            $this->err('  ! max page cap (' . $this->maxPages . ') reached; finalizing with pages gathered');
        }
        $this->out('');

        // 3. Save rendered HTML to mapped paths -------------------------------
        $this->out('[3/6] Saving rendered HTML');
        $pageFileByUrl = [];   // normalizedUrl => webroot-relative file path
        foreach ($result['order'] as $url) {
            $rel = UrlMapper::pageUrlToPath($url);
            $pageFileByUrl[$url] = $rel;
            $this->writeFile($webroot . '/' . $rel, $result['pages'][$url]);
            $manifest->addPage($url, $rel, 'fetched');
            $this->out('  ' . $url . '  ->  ' . $rel);
        }
        $this->out('');

        // 4. Collect + download assets (recursive CSS) ------------------------
        // Resolve relative refs against the page's directory base (a page saved at blog/index.html
        // is served at /blog/, so "logo.png" -> /blog/logo.png, not /logo.png).
        $assetUrls = [];
        foreach ($result['pages'] as $url => $html) {
            foreach (AssetCollector::collect($html, UrlMapper::pageBaseUrl($url)) as $a) {
                $assetUrls[$a] = true;
            }
        }
        $assetUrls = array_keys($assetUrls);
        $this->out('[4/6] Downloading assets (' . count($assetUrls) . ' found across pages)');

        $downloader = new Downloader();
        $assetPathByUrl = [];   // absoluteUrl => webroot-relative __static path
        $queue = $assetUrls;
        $seen = [];
        $ok = 0;
        $failed = 0;
        $nested = 0;
        $done = 0;
        while ($queue !== []) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $done++;

            $rel = UrlMapper::assetUrlToStaticPath($url);
            $host = parse_url($url, PHP_URL_HOST) ?? 'unknown-host';

            // Defense-in-depth: never write outside __static/ even if mapping produced a stray path.
            if (!str_starts_with($rel, '__static/') || str_contains('/' . $rel, '/../')) {
                $manifest->addError($url, 'unsafe-path');
                $this->err(sprintf('  [%d] SKIP (unsafe path) %s', $done, $url));
                $failed++;
                continue;
            }
            $dest = $webroot . '/' . $rel;

            if ($downloader->download($url, $dest)) {
                $assetPathByUrl[$url] = $rel;
                $manifest->addAsset($url, $host, $rel, 'downloaded');
                $ok++;
                $this->out(sprintf(
                    '  [%d] ok  %s  (%s)',
                    $done,
                    $url,
                    $this->humanBytes((int) @filesize($dest))
                ));

                // If CSS, scan for nested references and enqueue them.
                if ($this->looksLikeCss($url, $dest)) {
                    $css = (string) file_get_contents($dest);
                    foreach (CssRewriter::extractUrls($css, $url) as $nestedUrl) {
                        if (!isset($seen[$nestedUrl])) {
                            $queue[] = $nestedUrl;
                            $nested++;
                        }
                    }
                }
            } else {
                $manifest->addError($url, 'download-failed');
                $this->err(sprintf('  [%d] FAIL %s', $done, $url));
                $failed++;
            }
        }
        $this->out(sprintf(
            '  downloaded %d ok, %d failed (%d nested CSS asset(s) discovered)',
            $ok,
            $failed,
            $nested
        ));
        $this->out('');

        // 5. Rewrite CSS + HTML ----------------------------------------------
        $this->out('[5/6] Rewriting references to local paths');

        $cssFiles = 0;
        foreach ($assetPathByUrl as $url => $rel) {
            $dest = $webroot . '/' . $rel;
            if (!$this->looksLikeCss($url, $dest)) {
                continue;
            }
            $css = (string) file_get_contents($dest);
            $rewritten = CssRewriter::rewrite(
                $css,
                $url,
                fn (string $abs): ?string =>
                    isset($assetPathByUrl[$abs])
                        ? UrlMapper::relativeHref($rel, $assetPathByUrl[$abs])
                        : null
            );
            $this->writeFile($dest, $rewritten);
            $cssFiles++;
        }
        $this->out('  rewrote ' . $cssFiles . ' CSS file(s)');

        // Internal (same-domain, non-query) <a href> links become root-relative paths so the
        // archive is navigable at any deploy domain (the .htaccess serves /aboutus -> index.html).
        // External and query-string links are left absolute.
        $linkResolver = static function (string $abs) use ($crawler): ?string {
            if (!$crawler->inScope($abs)) {
                return null; // external domain -> leave the link as authored
            }
            if (!UrlMapper::isArchivablePageUrl($abs)) {
                return $abs; // in-scope query-string page (not archived) -> keep absolute
            }
            $path = parse_url($abs, PHP_URL_PATH) ?: '/';
            return $path === '/' ? '/' : (rtrim($path, '/') ?: '/');
        };

        foreach ($pageFileByUrl as $url => $rel) {
            $file = $webroot . '/' . $rel;
            $html = (string) file_get_contents($file);
            $rewritten = HtmlRewriter::rewrite(
                $html,
                UrlMapper::pageBaseUrl($url),
                fn (string $abs): ?string =>
                    isset($assetPathByUrl[$abs])
                        ? UrlMapper::relativeHref($rel, $assetPathByUrl[$abs])
                        : null,  // unknown (e.g. Google Fonts, failed) -> leave as-is
                $linkResolver
            );
            $this->writeFile($file, $rewritten);
            $this->out('  rewrote ' . $rel);
        }
        $this->out('');

        // 6. .htaccess + manifest --------------------------------------------
        $this->out('[6/6] Writing .htaccess and archive.json');
        HtaccessWriter::write($webroot);
        $manifest->finish(gmdate('c'));
        $manifest->write($folder . '/archive.json');
        $this->out('  wrote ' . $webroot . '/.htaccess');
        $this->out('  wrote ' . $folder . '/archive.json');
        $this->out('');

        $this->out(sprintf('Done in %.1fs.', microtime(true) - $t0));
        $this->out(sprintf('  pages: %d   assets: %d ok / %d failed', count($pageFileByUrl), $ok, $failed));
        $this->out('  deploy: ' . $webroot . '/   (open index.html or serve with Apache)');
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }

    private function err(string $line): void
    {
        fwrite(STDERR, $line . "\n");
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $value, $units[$i]);
    }

    private function looksLikeCss(string $url, string $path): bool
    {
        if (preg_match('#\.css(\?|$)#i', $url)) {
            return true;
        }
        return str_ends_with(strtolower($path), '.css');
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->mkdir(dirname($path));
        file_put_contents($path, $contents);
    }
}
