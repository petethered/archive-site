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
        $folder = rtrim($folder, '/');
        $webroot = $folder . '/webroot';
        $static = $webroot . '/__static';
        $this->mkdir($folder);
        $this->mkdir($webroot);
        $this->mkdir($static);

        $startedAt = gmdate('c');
        $manifest = new Manifest($seedUrl, $startedAt);
        $crawler = new Crawler($seedUrl);
        $browser = new Browser($this->chromeBinary);

        // 1. Crawl (render + discover) ----------------------------------------
        echo "Crawling {$seedUrl} ...\n";
        $result = $crawler->crawl(
            $seedUrl,
            $browser,
            $this->maxPages,
            function (string $url, string $reason) use ($manifest): void {
                fwrite(STDERR, "  ! render error {$url}: {$reason}\n");
                $manifest->addError($url, $reason);
            }
        );

        if ($result['capped']) {
            fwrite(STDERR, "  ! max page cap ({$this->maxPages}) reached; finalizing with pages gathered\n");
        }

        // 2. Save rendered HTML to mapped paths -------------------------------
        $pageFileByUrl = [];   // normalizedUrl => webroot-relative file path
        foreach ($result['order'] as $url) {
            $rel = UrlMapper::pageUrlToPath($url);
            $pageFileByUrl[$url] = $rel;
            $this->writeFile($webroot . '/' . $rel, $result['pages'][$url]);
            $manifest->addPage($url, $rel, 'fetched');
            echo "  page {$url} -> {$rel}\n";
        }

        // 3. Collect assets from every rendered page --------------------------
        $assetUrls = [];
        foreach ($result['pages'] as $url => $html) {
            foreach (AssetCollector::collect($html, $url) as $a) {
                $assetUrls[$a] = true;
            }
        }
        $assetUrls = array_keys($assetUrls);

        // 4. Download assets, recursively pulling nested CSS references -------
        $downloader = new Downloader();
        $assetPathByUrl = [];   // absoluteUrl => webroot-relative __static path
        $queue = $assetUrls;
        $seen = [];
        while ($queue !== []) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $rel = UrlMapper::assetUrlToStaticPath($url);
            $dest = $webroot . '/' . $rel;
            $host = parse_url($url, PHP_URL_HOST) ?? 'unknown-host';

            if ($downloader->download($url, $dest)) {
                $assetPathByUrl[$url] = $rel;
                $manifest->addAsset($url, $host, $rel, 'downloaded');

                // If CSS, scan for nested references and enqueue them.
                if ($this->looksLikeCss($url, $dest)) {
                    $css = (string) file_get_contents($dest);
                    foreach (CssRewriter::extractUrls($css, $url) as $nested) {
                        if (!isset($seen[$nested])) {
                            $queue[] = $nested;
                        }
                    }
                }
            } else {
                $manifest->addError($url, 'download-failed');
                fwrite(STDERR, "  ! download failed {$url}\n");
            }
        }

        // 5a. Rewrite downloaded CSS files to local relative paths ------------
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
        }

        // 5b. Rewrite each saved HTML page ------------------------------------
        foreach ($pageFileByUrl as $url => $rel) {
            $file = $webroot . '/' . $rel;
            $html = (string) file_get_contents($file);
            $rewritten = HtmlRewriter::rewrite(
                $html,
                $url,
                fn (string $abs): ?string =>
                    isset($assetPathByUrl[$abs])
                        ? UrlMapper::relativeHref($rel, $assetPathByUrl[$abs])
                        : null   // unknown (e.g. Google Fonts, failed) -> leave as-is
            );
            $this->writeFile($file, $rewritten);
        }

        // 6. .htaccess + manifest --------------------------------------------
        HtaccessWriter::write($webroot);
        $manifest->finish(gmdate('c'));
        $manifest->write($folder . '/archive.json');

        echo "Done. Archive written to {$folder}\n";
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
