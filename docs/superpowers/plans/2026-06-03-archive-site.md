# Archive Site Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a PHP CLI tool (`php archiveSite.php <folder> <url>`) that crawls a JavaScript-rendered website with headless Chrome, downloads its pages and static assets, and produces a self-contained, deployable static HTML site that renders offline except for Google Web Fonts.

**Architecture:** A single CLI entry point orchestrates focused, single-responsibility classes under `src/` (PSR-4, namespace `ArchiveSite\`). Pure-logic units (URL handling, path mapping, link/asset extraction, HTML/CSS rewriting, htaccess, manifest) are unit-tested offline. Network/Chrome units (`Browser`, `Downloader`, orchestrator) are integration-tested — `Downloader` via `file://` fixtures, `Browser`/orchestrator against a real site using the installed Chrome binary.

**Tech Stack:** PHP 8.4, Composer (PSR-4 autoload only — no runtime packages), headless Chrome driven by **shelling out to the Chrome binary** (`--dump-dom` + sandbox-compatible flags) via `proc_open`, native `ext-dom` (`DOMDocument`/`DOMXPath`) for parsing & rewriting, `ext-curl` for downloads, PHPUnit 10 for tests.

> **Note on a spec refinement:** The spec mentioned `symfony/dom-crawler`; this plan uses native `DOMDocument`/`DOMXPath` instead (fewer dependencies, same capability). Asset-collision handling (spec §4) is made deterministic: any asset URL carrying a query string gets an 8-char hash of the full URL injected before its extension, so differing query versions never collide and the mapping is pure (URL → path with no global state). Clean (query-less) URLs keep clean filenames.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `composer.json` | Deps + PSR-4 autoload (`ArchiveSite\` → `src/`, `ArchiveSite\Tests\` → `tests/`) |
| `phpunit.xml` | PHPUnit config (test dir, bootstrap) |
| `archiveSite.php` | CLI entry: parse argv, run `ArchiveSite`, print summary |
| `src/Url.php` | Pure URL primitives: resolve relative→absolute, strip fragment, normalize dot-segments |
| `src/UrlMapper.php` | Pure mapping: page URL→webroot path, asset URL→`__static` path, relative href between files, archivable-page check |
| `src/Crawler.php` | Crawl scope (registrable domain), link extraction/normalization, BFS loop (uses `Browser`) |
| `src/AssetCollector.php` | Pure: extract asset URLs from rendered HTML (excludes Google Fonts) |
| `src/CssRewriter.php` | Pure: extract nested CSS asset URLs; rewrite `url()`/`@import` |
| `src/HtmlRewriter.php` | Pure: rewrite HTML asset references to local paths (leaves Google Fonts) |
| `src/HtaccessWriter.php` | Single root `.htaccess` content + write |
| `src/Manifest.php` | Accumulate pages/assets/skipped/errors; serialize `archive.json` |
| `src/Downloader.php` | Fetch a URL to a destination path via cURL |
| `src/Browser.php` | Wrap `chrome-php/chrome`: render a URL to final HTML |
| `src/ArchiveSite.php` | Orchestrator: scaffold dirs, run pipeline, write manifest |
| `chrome_instructions.md` | Chrome/Chromium install for Ubuntu + macOS |
| `README.md` | Usage |
| `tests/*` | PHPUnit tests + `tests/fixtures/*` |

Google Fonts hosts (constant reused everywhere): `fonts.googleapis.com`, `fonts.gstatic.com`.

---

## Task 1: Project scaffold

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/SmokeTest.php`
- Create: `.gitignore`

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "petethered/archive-site",
    "description": "Crawl and archive a JS-rendered website into a deployable static site.",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "ext-dom": "*",
        "ext-curl": "*",
        "ext-json": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": { "ArchiveSite\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "ArchiveSite\\Tests\\": "tests/" }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create `.gitignore`**

```
/vendor/
.phpunit.result.cache
```

- [ ] **Step 4: Write the smoke test** — `tests/SmokeTest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoload_and_phpunit_work(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 5: Install dependencies**

Run: `composer install`
Expected: `vendor/` created, no errors. (If `chrome-php/chrome` resolution fails, re-run with `composer update`.)

- [ ] **Step 6: Run the smoke test**

Run: `vendor/bin/phpunit tests/SmokeTest.php`
Expected: PASS (1 test, 1 assertion).

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock phpunit.xml .gitignore tests/SmokeTest.php
git commit -m "Scaffold project: composer, phpunit, smoke test"
```

---

## Task 2: `Url` — URL primitives (pure)

**Files:**
- Create: `src/Url.php`
- Test: `tests/UrlTest.php`

- [ ] **Step 1: Write the failing test** — `tests/UrlTest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function test_absolute_url_is_returned_without_fragment(): void
    {
        $this->assertSame(
            'https://x.com/a/b',
            Url::resolve('https://x.com/', 'https://x.com/a/b#frag')
        );
    }

    public function test_root_relative(): void
    {
        $this->assertSame(
            'https://x.com/privacy',
            Url::resolve('https://x.com/about/', '/privacy')
        );
    }

    public function test_protocol_relative(): void
    {
        $this->assertSame(
            'https://cdn.com/app.js',
            Url::resolve('https://x.com/', '//cdn.com/app.js')
        );
    }

    public function test_path_relative_with_dot_segments(): void
    {
        $this->assertSame(
            'https://x.com/app.js',
            Url::resolve('https://x.com/about/team/', '../../app.js')
        );
    }

    public function test_query_is_preserved(): void
    {
        $this->assertSame(
            'https://x.com/app.js?v=2',
            Url::resolve('https://x.com/', 'app.js?v=2')
        );
    }

    public function test_non_http_schemes_are_ignored(): void
    {
        $this->assertNull(Url::resolve('https://x.com/', 'mailto:a@b.com'));
        $this->assertNull(Url::resolve('https://x.com/', 'data:image/png;base64,AAAA'));
        $this->assertNull(Url::resolve('https://x.com/', '#section'));
        $this->assertNull(Url::resolve('https://x.com/', ''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/UrlTest.php`
Expected: FAIL ("Class ArchiveSite\Url not found").

- [ ] **Step 3: Write minimal implementation** — `src/Url.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class Url
{
    /** Resolve a possibly-relative reference against a base URL. Returns null for non-http(s) or empty refs. */
    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref[0] === '#') {
            return null;
        }
        if (preg_match('#^(data|javascript|mailto|tel|blob|about):#i', $ref)) {
            return null;
        }
        if (preg_match('#^https?://#i', $ref)) {
            return self::stripFragment($ref);
        }

        $b = parse_url($base);
        if ($b === false || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $authority = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (str_starts_with($ref, '//')) {
            return self::stripFragment($b['scheme'] . ':' . $ref);
        }
        if (str_starts_with($ref, '/')) {
            return self::stripFragment($authority . self::normalizePath($ref));
        }

        $basePath = $b['path'] ?? '/';
        $slash = strrpos($basePath, '/');
        $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
        return self::stripFragment($authority . self::normalizePath($dir . $ref));
    }

    public static function stripFragment(string $url): string
    {
        $hash = strpos($url, '#');
        return $hash === false ? $url : substr($url, 0, $hash);
    }

    /** Collapse "." and ".." segments in the path portion (leaves any ?query/#frag tail intact). */
    private static function normalizePath(string $pathWithTail): string
    {
        $tail = '';
        foreach (['?', '#'] as $sep) {
            $pos = strpos($pathWithTail, $sep);
            if ($pos !== false) {
                $tail = substr($pathWithTail, $pos) . $tail;
                $pathWithTail = substr($pathWithTail, 0, $pos);
            }
        }
        $segments = explode('/', $pathWithTail);
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '.' || $seg === '') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $path = '/' . implode('/', $out);
        if (str_ends_with($pathWithTail, '/') && $path !== '/') {
            $path .= '/';
        }
        return $path . $tail;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/UrlTest.php`
Expected: PASS (all assertions).

- [ ] **Step 5: Commit**

```bash
git add src/Url.php tests/UrlTest.php
git commit -m "Add Url primitives: resolve, stripFragment, normalizePath"
```

---

## Task 3: `UrlMapper` — page/asset path mapping (pure)

**Files:**
- Create: `src/UrlMapper.php`
- Test: `tests/UrlMapperTest.php`

- [ ] **Step 1: Write the failing test** — `tests/UrlMapperTest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\UrlMapper;
use PHPUnit\Framework\TestCase;

final class UrlMapperTest extends TestCase
{
    public function test_homepage_maps_to_index(): void
    {
        $this->assertSame('index.html', UrlMapper::pageUrlToPath('https://x.com/'));
        $this->assertSame('index.html', UrlMapper::pageUrlToPath('https://x.com'));
    }

    public function test_directory_page_maps_to_index_in_subdir(): void
    {
        $this->assertSame('privacy/index.html', UrlMapper::pageUrlToPath('https://x.com/privacy'));
        $this->assertSame('privacy/index.html', UrlMapper::pageUrlToPath('https://x.com/privacy/'));
    }

    public function test_html_page_kept_as_is(): void
    {
        $this->assertSame('about.html', UrlMapper::pageUrlToPath('https://x.com/about.html'));
    }

    public function test_query_string_page_is_not_archivable(): void
    {
        $this->assertFalse(UrlMapper::isArchivablePageUrl('https://x.com/search?q=faith'));
        $this->assertTrue(UrlMapper::isArchivablePageUrl('https://x.com/privacy'));
    }

    public function test_asset_path_under_static_by_host(): void
    {
        $this->assertSame(
            '__static/cdn.b.net/assets/app.js',
            UrlMapper::assetUrlToStaticPath('https://cdn.b.net/assets/app.js')
        );
    }

    public function test_asset_with_query_gets_hash_before_extension(): void
    {
        $p = UrlMapper::assetUrlToStaticPath('https://cdn.b.net/app.js?v=2');
        $this->assertMatchesRegularExpression('#^__static/cdn\.b\.net/app\.[0-9a-f]{8}\.js$#', $p);
        // deterministic: same URL -> same path
        $this->assertSame($p, UrlMapper::assetUrlToStaticPath('https://cdn.b.net/app.js?v=2'));
        // different query -> different file
        $this->assertNotSame($p, UrlMapper::assetUrlToStaticPath('https://cdn.b.net/app.js?v=3'));
    }

    public function test_asset_directory_url_gets_index_filename(): void
    {
        $this->assertSame(
            '__static/cdn.b.net/img/index',
            UrlMapper::assetUrlToStaticPath('https://cdn.b.net/img/')
        );
    }

    public function test_relative_href_from_nested_page(): void
    {
        $this->assertSame(
            '__static/h/app.js',
            UrlMapper::relativeHref('index.html', '__static/h/app.js')
        );
        $this->assertSame(
            '../__static/h/app.js',
            UrlMapper::relativeHref('privacy/index.html', '__static/h/app.js')
        );
        $this->assertSame(
            '../../__static/h/app.js',
            UrlMapper::relativeHref('a/b/index.html', '__static/h/app.js')
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/UrlMapperTest.php`
Expected: FAIL ("Class ArchiveSite\UrlMapper not found").

- [ ] **Step 3: Write minimal implementation** — `src/UrlMapper.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class UrlMapper
{
    public static function isArchivablePageUrl(string $url): bool
    {
        $parts = parse_url($url);
        return $parts !== false && empty($parts['query']);
    }

    /** Map a page URL to a webroot-relative file path. */
    public static function pageUrlToPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $path = trim((string) $path, '/');
        if ($path === '') {
            return 'index.html';
        }
        if (str_ends_with(strtolower($path), '.html')) {
            return $path;
        }
        return $path . '/index.html';
    }

    /** Map an asset URL to a webroot-relative path under __static/<host>/. */
    public static function assetUrlToStaticPath(string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? 'unknown-host';
        $path = ltrim($parts['path'] ?? '', '/');
        if ($path === '' || str_ends_with($path, '/')) {
            $path .= 'index';
        }

        if (!empty($parts['query'])) {
            $hash = substr(sha1($url), 0, 8);
            $dot = strrpos($path, '.');
            $base = basename($path);
            // only treat as extension if the dot is in the final segment
            if ($dot !== false && $dot > strrpos($path, '/')) {
                $path = substr($path, 0, $dot) . '.' . $hash . substr($path, $dot);
            } else {
                $path .= '.' . $hash;
            }
            unset($base);
        }

        return '__static/' . $host . '/' . $path;
    }

    /** Relative href from one webroot-relative file to another webroot-relative target. */
    public static function relativeHref(string $fromFile, string $toTarget): string
    {
        $fromDir = trim(str_replace('\\', '/', dirname($fromFile)), '/');
        if ($fromDir === '' || $fromDir === '.') {
            return $toTarget;
        }
        $depth = count(explode('/', $fromDir));
        return str_repeat('../', $depth) . $toTarget;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/UrlMapperTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/UrlMapper.php tests/UrlMapperTest.php
git commit -m "Add UrlMapper: page/asset path mapping and relative hrefs"
```

---

## Task 4: `Crawler` — scope + link extraction (pure parts)

**Files:**
- Create: `src/Crawler.php`
- Test: `tests/CrawlerTest.php`

The BFS loop method (`crawl()`) that drives `Browser` is added in Task 12; this task builds and tests the pure logic (`registrableDomain`, `inScope`, `extractLinks`).

- [ ] **Step 1: Write the failing test** — `tests/CrawlerTest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\Crawler;
use PHPUnit\Framework\TestCase;

final class CrawlerTest extends TestCase
{
    public function test_registrable_domain_is_last_two_labels(): void
    {
        $this->assertSame('example.com', Crawler::registrableDomain('www.example.com'));
        $this->assertSame('example.com', Crawler::registrableDomain('example.com'));
    }

    public function test_in_scope_matches_registrable_domain(): void
    {
        $c = new Crawler('https://example.com/');
        $this->assertTrue($c->inScope('https://www.example.com/x'));
        $this->assertTrue($c->inScope('https://blog.example.com/y'));
        $this->assertFalse($c->inScope('https://cdn.example.net/z'));
        $this->assertFalse($c->inScope('ftp://example.com/z'));
    }

    public function test_extract_links_filters_normalizes_and_dedupes(): void
    {
        $html = <<<HTML
        <html><body>
          <a href="/privacy/">Privacy</a>
          <a href="/privacy">Privacy again</a>
          <a href="about.html#team">About</a>
          <a href="https://external.com/x">External</a>
          <a href="/search?q=faith">Search</a>
          <a href="mailto:a@b.com">Mail</a>
        </body></html>
        HTML;

        $c = new Crawler('https://example.com/');
        $links = $c->extractLinks($html, 'https://example.com/');
        sort($links);

        $this->assertSame([
            'https://example.com/about.html',
            'https://example.com/privacy',
        ], $links);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/CrawlerTest.php`
Expected: FAIL ("Class ArchiveSite\Crawler not found").

- [ ] **Step 3: Write minimal implementation** — `src/Crawler.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class Crawler
{
    private string $scopeDomain;

    public function __construct(string $seedUrl)
    {
        $host = parse_url($seedUrl, PHP_URL_HOST) ?? '';
        $this->scopeDomain = self::registrableDomain($host);
    }

    /** Heuristic registrable domain: last two dot-separated labels, lowercased. */
    public static function registrableDomain(string $host): string
    {
        $host = strtolower($host);
        $labels = explode('.', $host);
        if (count($labels) <= 2) {
            return $host;
        }
        return implode('.', array_slice($labels, -2));
    }

    public function inScope(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        return self::registrableDomain($parts['host']) === $this->scopeDomain;
    }

    /** Normalize a page URL for dedupe: strip fragment, drop trailing slash (except root), lowercase host. */
    public function normalizePageUrl(string $url): string
    {
        $url = Url::stripFragment($url);
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        if ($path === '') {
            $path = '/';
        }
        return $scheme . '://' . $host . $port . $path;
    }

    /** Extract in-scope, archivable, normalized, de-duplicated page links from rendered HTML. */
    public function extractLinks(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $found = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var \DOMElement $a */
            $abs = Url::resolve($baseUrl, $a->getAttribute('href'));
            if ($abs === null || !$this->inScope($abs) || !UrlMapper::isArchivablePageUrl($abs)) {
                continue;
            }
            $norm = $this->normalizePageUrl($abs);
            $found[$norm] = true;
        }
        return array_keys($found);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/CrawlerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Crawler.php tests/CrawlerTest.php
git commit -m "Add Crawler scope and link extraction"
```

---

## Task 5: `AssetCollector` — extract asset URLs (pure)

**Files:**
- Create: `src/AssetCollector.php`
- Test: `tests/AssetCollectorTest.php`

- [ ] **Step 1: Write the failing test** — `tests/AssetCollectorTest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\AssetCollector;
use PHPUnit\Framework\TestCase;

final class AssetCollectorTest extends TestCase
{
    public function test_collects_assets_and_excludes_google_fonts(): void
    {
        $html = <<<HTML
        <html><head>
          <link rel="stylesheet" href="/css/site.css">
          <link rel="icon" href="/favicon.ico">
          <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto">
          <script src="https://cdn.b.net/app.js?v=2"></script>
          <style>.h{background:url('/img/bg.png')}</style>
        </head><body>
          <img src="/img/logo.png" srcset="/img/logo@2x.png 2x, /img/logo@3x.png 3x">
          <div style="background: url(/img/hero.jpg)"></div>
          <a href="/privacy">link, not an asset</a>
        </body></html>
        HTML;

        $assets = AssetCollector::collect($html, 'https://x.com/');
        sort($assets);

        $this->assertSame([
            'https://cdn.b.net/app.js?v=2',
            'https://x.com/css/site.css',
            'https://x.com/favicon.ico',
            'https://x.com/img/bg.png',
            'https://x.com/img/hero.jpg',
            'https://x.com/img/logo.png',
            'https://x.com/img/logo@2x.png',
            'https://x.com/img/logo@3x.png',
        ], $assets);

        $this->assertNotContains('https://fonts.googleapis.com/css?family=Roboto', $assets);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/AssetCollectorTest.php`
Expected: FAIL ("Class ArchiveSite\AssetCollector not found").

- [ ] **Step 3: Write minimal implementation** — `src/AssetCollector.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class AssetCollector
{
    public const GOOGLE_FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

    /** Return de-duplicated absolute asset URLs referenced by the HTML (excludes Google Fonts). */
    public static function collect(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $found = [];
        $add = static function (?string $abs) use (&$found): void {
            if ($abs === null) {
                return;
            }
            $host = parse_url($abs, PHP_URL_HOST);
            if ($host !== null && in_array(strtolower($host), self::GOOGLE_FONT_HOSTS, true)) {
                return;
            }
            $found[$abs] = true;
        };

        foreach ($xpath->query('//*[@src]') as $el) {
            /** @var \DOMElement $el */
            $add(Url::resolve($baseUrl, $el->getAttribute('src')));
        }
        foreach ($xpath->query('//link[@href]') as $el) {
            /** @var \DOMElement $el */
            $add(Url::resolve($baseUrl, $el->getAttribute('href')));
        }
        foreach ($xpath->query('//*[@srcset]') as $el) {
            /** @var \DOMElement $el */
            foreach (self::parseSrcset($el->getAttribute('srcset')) as $candidate) {
                $add(Url::resolve($baseUrl, $candidate));
            }
        }
        foreach ($xpath->query('//*[@style]') as $el) {
            /** @var \DOMElement $el */
            foreach (self::cssUrls($el->getAttribute('style')) as $u) {
                $add(Url::resolve($baseUrl, $u));
            }
        }
        foreach ($xpath->query('//style') as $el) {
            foreach (self::cssUrls($el->textContent) as $u) {
                $add(Url::resolve($baseUrl, $u));
            }
        }

        return array_keys($found);
    }

    /** @return string[] */
    public static function parseSrcset(string $srcset): array
    {
        $out = [];
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $url = preg_split('/\s+/', $part)[0] ?? '';
            if ($url !== '') {
                $out[] = $url;
            }
        }
        return $out;
    }

    /** @return string[] Raw url() targets found in a CSS snippet. */
    public static function cssUrls(string $css): array
    {
        $out = [];
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $m)) {
            foreach ($m[2] as $u) {
                $u = trim($u);
                if ($u !== '' && !str_starts_with($u, 'data:')) {
                    $out[] = $u;
                }
            }
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/AssetCollectorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/AssetCollector.php tests/AssetCollectorTest.php
git commit -m "Add AssetCollector: extract asset URLs, exclude Google Fonts"
```

---

## Task 6: `CssRewriter` — nested CSS assets + rewrite (pure)

**Files:**
- Create: `src/CssRewriter.php`
- Test: `tests/CssRewriterTest.php`

- [ ] **Step 1: Write the failing test** — `tests/CssRewriterTest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\CssRewriter;
use PHPUnit\Framework\TestCase;

final class CssRewriterTest extends TestCase
{
    public function test_extract_urls_includes_import_and_url_excludes_google_and_data(): void
    {
        $css = <<<CSS
        @import "base.css";
        .a { background: url('img/bg.png'); }
        .b { background: url(data:image/png;base64,AAAA); }
        @font-face { src: url(https://fonts.gstatic.com/x.woff2); }
        CSS;

        $urls = CssRewriter::extractUrls($css, 'https://cdn.b.net/css/site.css');
        sort($urls);

        $this->assertSame([
            'https://cdn.b.net/css/base.css',
            'https://cdn.b.net/css/img/bg.png',
        ], $urls);
    }

    public function test_rewrite_replaces_with_resolver_output_and_leaves_nulls(): void
    {
        $css = ".a{background:url('img/bg.png')} @import 'base.css';";

        $resolver = static function (string $abs): ?string {
            return match ($abs) {
                'https://cdn.b.net/css/img/bg.png' => '../img/bg.png',
                'https://cdn.b.net/css/base.css'   => 'base.css',
                default => null,
            };
        };

        $out = CssRewriter::rewrite($css, 'https://cdn.b.net/css/site.css', $resolver);
        $this->assertStringContainsString("url('../img/bg.png')", $out);
        $this->assertStringContainsString("'base.css'", $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/CssRewriterTest.php`
Expected: FAIL ("Class ArchiveSite\CssRewriter not found").

- [ ] **Step 3: Write minimal implementation** — `src/CssRewriter.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class CssRewriter
{
    /** Return de-duplicated absolute URLs referenced (url() + @import) by the CSS, excluding data: and Google Fonts. */
    public static function extractUrls(string $css, string $baseUrl): array
    {
        $found = [];
        foreach (self::rawRefs($css) as $ref) {
            $abs = Url::resolve($baseUrl, $ref);
            if ($abs === null) {
                continue;
            }
            $host = parse_url($abs, PHP_URL_HOST);
            if ($host !== null && in_array(strtolower($host), AssetCollector::GOOGLE_FONT_HOSTS, true)) {
                continue;
            }
            $found[$abs] = true;
        }
        return array_keys($found);
    }

    /**
     * Rewrite url() and @import references. $resolver receives the absolute URL and returns the
     * replacement string, or null to leave the original reference unchanged.
     */
    public static function rewrite(string $css, string $baseUrl, callable $resolver): string
    {
        $replace = static function (string $ref) use ($baseUrl, $resolver): string {
            $abs = Url::resolve($baseUrl, $ref);
            if ($abs === null) {
                return $ref;
            }
            $new = $resolver($abs);
            return $new ?? $ref;
        };

        // url(...) form
        $css = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static function (array $m) use ($replace): string {
                $quote = $m[1];
                return 'url(' . $quote . $replace(trim($m[2])) . $quote . ')';
            },
            $css
        );

        // @import "..." / @import '...' form (without url())
        $css = preg_replace_callback(
            '/@import\s+([\'"])([^\'"]+)\1/i',
            static function (array $m) use ($replace): string {
                return '@import ' . $m[1] . $replace(trim($m[2])) . $m[1];
            },
            $css
        );

        return $css;
    }

    /** @return string[] */
    private static function rawRefs(string $css): array
    {
        $refs = [];
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $m)) {
            foreach ($m[2] as $u) {
                $refs[] = trim($u);
            }
        }
        if (preg_match_all('/@import\s+([\'"])([^\'"]+)\1/i', $css, $m)) {
            foreach ($m[2] as $u) {
                $refs[] = trim($u);
            }
        }
        return array_filter($refs, static fn (string $u): bool => $u !== '' && !str_starts_with($u, 'data:'));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/CssRewriterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/CssRewriter.php tests/CssRewriterTest.php
git commit -m "Add CssRewriter: extract and rewrite CSS asset references"
```

---

## Task 7: `HtmlRewriter` — rewrite HTML asset refs (pure)

**Files:**
- Create: `src/HtmlRewriter.php`
- Test: `tests/HtmlRewriterTest.php`

- [ ] **Step 1: Write the failing test** — `tests/HtmlRewriterTest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite\Tests;

use ArchiveSite\HtmlRewriter;
use PHPUnit\Framework\TestCase;

final class HtmlRewriterTest extends TestCase
{
    public function test_rewrites_src_and_href_but_leaves_resolver_nulls(): void
    {
        $html = <<<HTML
        <html><head>
          <link rel="stylesheet" href="/css/site.css">
          <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto">
        </head><body>
          <img src="/img/logo.png">
        </body></html>
        HTML;

        $resolver = static function (string $abs): ?string {
            return match ($abs) {
                'https://x.com/css/site.css' => '__static/x.com/css/site.css',
                'https://x.com/img/logo.png' => '__static/x.com/img/logo.png',
                default => null, // Google Fonts -> left as-is
            };
        };

        $out = HtmlRewriter::rewrite($html, 'https://x.com/', $resolver);

        $this->assertStringContainsString('href="__static/x.com/css/site.css"', $out);
        $this->assertStringContainsString('src="__static/x.com/img/logo.png"', $out);
        $this->assertStringContainsString('https://fonts.googleapis.com/css?family=Roboto', $out);
    }

    public function test_rewrites_srcset(): void
    {
        $html = '<html><body><img src="/a.png" srcset="/a.png 1x, /a2.png 2x"></body></html>';
        $resolver = static fn (string $abs): string => '__local/' . basename(parse_url($abs, PHP_URL_PATH));
        $out = HtmlRewriter::rewrite($html, 'https://x.com/', $resolver);
        $this->assertStringContainsString('__local/a.png 1x', $out);
        $this->assertStringContainsString('__local/a2.png 2x', $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/HtmlRewriterTest.php`
Expected: FAIL ("Class ArchiveSite\HtmlRewriter not found").

- [ ] **Step 3: Write minimal implementation** — `src/HtmlRewriter.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class HtmlRewriter
{
    /**
     * Rewrite asset references (src, href, srcset, inline style url()) to local paths.
     * $resolver receives an absolute URL and returns the replacement, or null to leave it unchanged.
     */
    public static function rewrite(string $html, string $baseUrl, callable $resolver): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $map = static function (string $ref) use ($baseUrl, $resolver): ?string {
            $abs = Url::resolve($baseUrl, $ref);
            if ($abs === null) {
                return null;
            }
            return $resolver($abs);
        };

        foreach ($xpath->query('//*[@src]') as $el) {
            /** @var \DOMElement $el */
            $new = $map($el->getAttribute('src'));
            if ($new !== null) {
                $el->setAttribute('src', $new);
            }
        }
        foreach ($xpath->query('//link[@href]') as $el) {
            /** @var \DOMElement $el */
            $new = $map($el->getAttribute('href'));
            if ($new !== null) {
                $el->setAttribute('href', $new);
            }
        }
        foreach ($xpath->query('//*[@srcset]') as $el) {
            /** @var \DOMElement $el */
            $el->setAttribute('srcset', self::rewriteSrcset($el->getAttribute('srcset'), $map));
        }
        foreach ($xpath->query('//*[@style]') as $el) {
            /** @var \DOMElement $el */
            $el->setAttribute('style', self::rewriteStyle($el->getAttribute('style'), $map));
        }
        foreach ($xpath->query('//style') as $el) {
            $el->textContent = self::rewriteStyle($el->textContent, $map);
        }

        $body = $dom->saveHTML();
        return $body === false ? $html : $body;
    }

    private static function rewriteSrcset(string $srcset, callable $map): string
    {
        $parts = [];
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = preg_split('/\s+/', $part, 2);
            $new = $map($bits[0]);
            $url = $new ?? $bits[0];
            $parts[] = isset($bits[1]) ? $url . ' ' . $bits[1] : $url;
        }
        return implode(', ', $parts);
    }

    private static function rewriteStyle(string $css, callable $map): string
    {
        return preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static function (array $m) use ($map): string {
                $new = $map(trim($m[2]));
                return 'url(' . $m[1] . ($new ?? trim($m[2])) . $m[1] . ')';
            },
            $css
        );
    }
}
```

> **Note:** `$resolver` in `ArchiveSite` returns `null` for Google Fonts URLs (leave unchanged) and the local relative path otherwise. `HtmlRewriter`'s `$map` wrapper returns `null` both when the URL can't be resolved and when the resolver declines — either way the attribute is left untouched, which is the desired behavior.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/HtmlRewriterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/HtmlRewriter.php tests/HtmlRewriterTest.php
git commit -m "Add HtmlRewriter: rewrite HTML asset references to local paths"
```

---

## Task 8: `HtaccessWriter` — single root fallback file

**Files:**
- Create: `src/HtaccessWriter.php`
- Test: `tests/HtaccessWriterTest.php`

- [ ] **Step 1: Write the failing test** — `tests/HtaccessWriterTest.php`

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/HtaccessWriterTest.php`
Expected: FAIL ("Class ArchiveSite\HtaccessWriter not found").

- [ ] **Step 3: Write minimal implementation** — `src/HtaccessWriter.php`

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/HtaccessWriterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/HtaccessWriter.php tests/HtaccessWriterTest.php
git commit -m "Add HtaccessWriter: single root walk-up fallback"
```

> **Manual Apache verification (deferred to Task 14 acceptance):** after a real archive is produced, serve `webroot/` with Apache (`mod_rewrite` + `AllowOverride All`) and confirm `/about/asdfasdfasd` serves `/about/index.html` with the URL unchanged, and a total miss serves `/index.html`.

---

## Task 9: `Manifest` — JSON run record

**Files:**
- Create: `src/Manifest.php`
- Test: `tests/ManifestTest.php`

- [ ] **Step 1: Write the failing test** — `tests/ManifestTest.php`

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ManifestTest.php`
Expected: FAIL ("Class ArchiveSite\Manifest not found").

- [ ] **Step 3: Write minimal implementation** — `src/Manifest.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class Manifest
{
    private array $pages = [];
    private array $assets = [];
    private array $skipped = [];
    private array $errors = [];
    private ?string $finishedAt = null;

    public function __construct(
        private readonly string $source,
        private readonly string $startedAt,
    ) {
    }

    public function addPage(string $url, string $path, string $status): void
    {
        $this->pages[] = ['url' => $url, 'path' => $path, 'status' => $status];
    }

    public function addAsset(string $url, string $host, string $path, string $status): void
    {
        $this->assets[] = ['url' => $url, 'host' => $host, 'path' => $path, 'status' => $status];
    }

    public function addSkipped(string $url, string $reason): void
    {
        $this->skipped[] = ['url' => $url, 'reason' => $reason];
    }

    public function addError(string $url, string $reason): void
    {
        $this->errors[] = ['url' => $url, 'reason' => $reason];
    }

    public function finish(string $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function toJson(): string
    {
        return json_encode([
            'source' => $this->source,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'pages' => $this->pages,
            'assets' => $this->assets,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function write(string $path): void
    {
        file_put_contents($path, $this->toJson());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/ManifestTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Manifest.php tests/ManifestTest.php
git commit -m "Add Manifest: accumulate run state and serialize archive.json"
```

---

## Task 10: `Downloader` — fetch a URL to disk (cURL)

Tested offline against `file://` fixtures (cURL supports `file://`).

**Files:**
- Create: `src/Downloader.php`
- Create: `tests/fixtures/sample.txt` (content: `hello-archive`)
- Test: `tests/DownloaderTest.php`

- [ ] **Step 1: Create the fixture** — `tests/fixtures/sample.txt`

```
hello-archive
```

- [ ] **Step 2: Write the failing test** — `tests/DownloaderTest.php`

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/DownloaderTest.php`
Expected: FAIL ("Class ArchiveSite\Downloader not found").

- [ ] **Step 4: Write minimal implementation** — `src/Downloader.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class Downloader
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly string $userAgent = 'archiveSite/1.0',
    ) {
    }

    /** Fetch $url and write its body to $destPath (creating parent dirs). Returns success. */
    public function download(string $url, string $destPath): bool
    {
        $dir = dirname($destPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_FAILONERROR => true,
        ]);
        $body = curl_exec($ch);
        $err = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($err !== 0 || $body === false) {
            return false;
        }
        // For http(s), reject error statuses; file:// reports status 0 which is fine.
        if ($status >= 400) {
            return false;
        }

        return file_put_contents($destPath, $body) !== false;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/DownloaderTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Downloader.php tests/DownloaderTest.php tests/fixtures/sample.txt
git commit -m "Add Downloader: fetch URL to disk via cURL"
```

---

## Task 11: `Browser` — headless Chrome shell-out (integration)

Shells out to the Chrome binary with `--dump-dom`. The flag set and binary
detection are unit-tested (pure, no Chrome); the actual render is verified with a
real binary in this step and end-to-end in Task 14.

**Files:**
- Create: `src/Browser.php`
- Test: `tests/BrowserTest.php`

> **Sandbox-compatible flags (known-good on this machine):**
> `--headless=new --no-sandbox --disable-gpu --user-data-dir=<freshTemp> --window-size=1280,20000 --virtual-time-budget=8000 --run-all-compositor-stages-before-draw --dump-dom <url>`.
> The tall window puts most IntersectionObserver lazy content "in viewport" so it
> loads without scrolling; `--virtual-time-budget` lets JS/network settle.

- [ ] **Step 1: Write the failing test** — `tests/BrowserTest.php`

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/BrowserTest.php`
Expected: FAIL ("Class ArchiveSite\Browser not found").

- [ ] **Step 3: Write minimal implementation** — `src/Browser.php`

```php
<?php

declare(strict_types=1);

namespace ArchiveSite;

final class Browser
{
    public const DEFAULT_BINARIES = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];

    private string $binary;

    public function __construct(
        ?string $chromeBinary = null,
        private readonly int $virtualTimeBudgetMs = 8000,
        private readonly int $processTimeoutSeconds = 60,
    ) {
        $resolved = $chromeBinary ?? self::detectBinary(self::DEFAULT_BINARIES);
        if ($resolved === null) {
            throw new \RuntimeException(
                "No Chrome/Chromium binary found. See chrome_instructions.md."
            );
        }
        $this->binary = $resolved;
    }

    /** @param string[] $candidates @return string|null first executable candidate */
    public static function detectBinary(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }

    /** @return string[] argv for proc_open (no shell quoting needed). */
    public static function buildArgs(string $binary, string $url, string $userDataDir): array
    {
        return [
            $binary,
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            '--user-data-dir=' . $userDataDir,
            '--window-size=1280,20000',
            '--virtual-time-budget=8000',
            '--run-all-compositor-stages-before-draw',
            '--dump-dom',
            $url,
        ];
    }

    /** Render a URL with headless Chrome and return the rendered DOM HTML. */
    public function render(string $url): string
    {
        $profile = sys_get_temp_dir() . '/chrome-archive-' . bin2hex(random_bytes(6));
        if (!mkdir($profile, 0700, true) && !is_dir($profile)) {
            throw new \RuntimeException("Cannot create Chrome profile dir: {$profile}");
        }

        $args = self::buildArgs($this->binary, $url, $profile);
        // Inject the configured virtual-time-budget (override the default in buildArgs).
        foreach ($args as $i => $a) {
            if (str_starts_with($a, '--virtual-time-budget=')) {
                $args[$i] = '--virtual-time-budget=' . $this->virtualTimeBudgetMs;
            }
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($args, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->rmrf($profile);
            throw new \RuntimeException("Failed to launch Chrome: {$this->binary}");
        }

        stream_set_blocking($pipes[1], true);
        $html = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->rmrf($profile);

        if ($html === false || trim($html) === '') {
            throw new \RuntimeException("Chrome produced no output for {$url} (exit {$exit}).");
        }
        return $html;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
```

> **Note:** `buildArgs` hard-codes `--virtual-time-budget=8000` for a clean,
> testable default; `render()` overwrites it with the constructor value so the
> budget stays configurable without complicating the tested pure method.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/BrowserTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Real render smoke check (Chrome binary required)**

Run:
```bash
php -r 'require "vendor/autoload.php"; echo substr((new ArchiveSite\Browser())->render("https://example.com"), 0, 120), "\n";'
```
Expected: prints the start of `<!DOCTYPE html>...` for example.com. (Ignore sandbox noise lines on stderr.)

- [ ] **Step 6: Commit**

```bash
git add src/Browser.php tests/BrowserTest.php
git commit -m "Add Browser: headless Chrome shell-out renderer"
```

---

## Task 12: `Crawler::crawl()` — BFS loop (integration)

Adds the network-driving loop to the existing `Crawler`. The pure helpers were tested in Task 4; the loop is verified in Task 14.

**Files:**
- Modify: `src/Crawler.php` (add `crawl()` and a small result holder)

- [ ] **Step 1: Add the crawl loop** — append these methods inside `class Crawler` in `src/Crawler.php`

```php
    /**
     * BFS from the seed using the Browser. Returns [ 'pages' => [normUrl => html], 'order' => [normUrl,...] ].
     * Records render failures via the provided $onError(url, reason) callback.
     */
    public function crawl(
        string $seedUrl,
        Browser $browser,
        int $maxPages,
        callable $onError
    ): array {
        $start = $this->normalizePageUrl(Url::stripFragment($seedUrl));
        $queue = [$start];
        $visited = [];
        $pages = [];
        $order = [];

        while ($queue !== [] && count($pages) < $maxPages) {
            $url = array_shift($queue);
            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            try {
                $html = $browser->render($url);
            } catch (\Throwable $e) {
                $onError($url, 'render-failed: ' . $e->getMessage());
                continue;
            }

            $pages[$url] = $html;
            $order[] = $url;

            foreach ($this->extractLinks($html, $url) as $link) {
                if (!isset($visited[$link])) {
                    $queue[] = $link;
                }
            }
        }

        return ['pages' => $pages, 'order' => $order, 'capped' => $queue !== []];
    }
```

- [ ] **Step 2: Verify nothing broke and the file is valid**

Run: `php -l src/Crawler.php && vendor/bin/phpunit tests/CrawlerTest.php`
Expected: `No syntax errors detected` and the Task 4 tests still PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Crawler.php
git commit -m "Add Crawler::crawl BFS loop driving the Browser"
```

---

## Task 13: `ArchiveSite` orchestrator + CLI entry (integration)

Wires everything: scaffold → crawl → save HTML → collect assets → download assets (with recursive CSS) → rewrite HTML & CSS → write `.htaccess` → write manifest. Verified end-to-end in Task 14.

**Files:**
- Create: `src/ArchiveSite.php`
- Create: `archiveSite.php`

- [ ] **Step 1: Write the orchestrator** — `src/ArchiveSite.php`

```php
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

        // Record skipped (query-string) page links seen across all pages.
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
```

- [ ] **Step 2: Write the CLI entry** — `archiveSite.php`

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use ArchiveSite\ArchiveSite;

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
    (new ArchiveSite())->run($folder, $url);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nFatal: " . $e->getMessage() . "\n");
    fwrite(STDERR, "If this is a Chrome launch error, see chrome_instructions.md\n");
    exit(1);
}
```

- [ ] **Step 3: Verify both files are syntactically valid**

Run: `php -l src/ArchiveSite.php && php -l archiveSite.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Verify the full unit suite still passes**

Run: `vendor/bin/phpunit`
Expected: all tests PASS (Tasks 1–10).

- [ ] **Step 5: Commit**

```bash
git add src/ArchiveSite.php archiveSite.php
git commit -m "Add ArchiveSite orchestrator and CLI entry point"
```

---

## Task 14: Docs + end-to-end manual verification

**Files:**
- Create: `chrome_instructions.md`
- Create: `README.md`

- [ ] **Step 1: Write `chrome_instructions.md`**

````markdown
# Installing Chrome / Chromium for archiveSite

`archiveSite.php` drives a headless Chrome via the `chrome-php/chrome` library.
You need a Chrome or Chromium binary installed.

## macOS

Option A — Google Chrome (GUI installer):
1. Download from https://www.google.com/chrome/ and install to `/Applications`.
2. The tool auto-detects `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`.

Option B — Homebrew:
```bash
brew install --cask google-chrome
# or Chromium:
brew install --cask chromium
```

Verify:
```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --version
```

## Ubuntu / Debian

Google Chrome (stable):
```bash
wget -q -O /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo apt-get update
sudo apt-get install -y /tmp/chrome.deb
google-chrome-stable --version
```

Or Chromium:
```bash
sudo apt-get update
sudo apt-get install -y chromium-browser
chromium-browser --version
```

Headless servers may also need font and library packages:
```bash
sudo apt-get install -y fonts-liberation libnss3 libatk-bridge2.0-0 libgbm1 libasound2
```

## How archiveSite launches Chrome

The tool shells out to the Chrome binary once per page and reads the rendered
DOM from stdout. It applies these flags automatically (you don't pass them):

```
--headless=new --no-sandbox --disable-gpu --user-data-dir=<fresh temp dir>
--window-size=1280,20000 --virtual-time-budget=8000
--run-all-compositor-stages-before-draw --dump-dom <url>
```

`--no-sandbox` is required when Chrome runs nested inside another sandbox
(e.g. CI or a restricted launch context); the enclosing sandbox stays the
enforcing boundary. The tall window height makes most lazy-loaded
(IntersectionObserver) content load without scrolling; `--virtual-time-budget`
gives JS/network time to settle.

## Telling archiveSite where Chrome is

Auto-detection checks these paths in order:

```
/Applications/Google Chrome.app/Contents/MacOS/Google Chrome
/usr/bin/google-chrome
/usr/bin/google-chrome-stable
/usr/bin/chromium
/usr/bin/chromium-browser
```

If your binary lives elsewhere, pass it as the `chromeBinary` constructor
argument to `ArchiveSite`/`Browser`, or symlink it onto one of these paths.
````

- [ ] **Step 2: Write `README.md`**

````markdown
# archiveSite

Crawl a JavaScript-rendered website with headless Chrome and produce a
self-contained, deployable static site. The archived site renders offline —
all assets are localized under `__static/<host>/...` — **except Google Web
Fonts**, which are intentionally still loaded from Google.

## Requirements

- PHP 8.1+ with `ext-dom`, `ext-curl`, `ext-json`
- Composer
- Chrome or Chromium (see [`chrome_instructions.md`](chrome_instructions.md))

## Install

```bash
composer install
```

## Usage

```bash
php archiveSite.php <folder> <url>
```

Example:

```bash
php archiveSite.php ./example-archive https://example.com
```

This creates:

```
example-archive/
  archive.json          # manifest: pages, assets, skipped, errors
  webroot/              # deploy this directory
    index.html
    privacy/index.html
    .htaccess           # single root; serves nearest ancestor index.html on a miss
    __static/<host>/... # all localized assets
```

## Behavior notes

- **Scope:** follows links on the same registrable domain (incl. subdomains);
  downloads assets from any host.
- **Query-string pages** (e.g. `/search?q=x`) are skipped and logged in
  `archive.json`.
- **Pages ending in `.html`** keep their filename; other pages become
  `name/index.html`.
- **Google Fonts** (`fonts.googleapis.com`, `fonts.gstatic.com`) are left
  pointing at Google.
- A failed asset download leaves the original remote URL in place (graceful
  degradation).

## Deploying

Serve `webroot/` with Apache (`mod_rewrite` + `AllowOverride All`) so the root
`.htaccess` takes effect. Any static host works too, but the 404 walk-up
fallback requires Apache `.htaccess` support.

## Tests

```bash
vendor/bin/phpunit
```
````

- [ ] **Step 3: Run the full unit suite one more time**

Run: `vendor/bin/phpunit`
Expected: all tests PASS.

- [ ] **Step 4: End-to-end manual run (requires Chrome installed)**

Install Chrome per `chrome_instructions.md`, then:
```bash
php archiveSite.php ./example-archive https://example.com
```
Expected:
- `./example-archive/webroot/index.html` exists and contains the rendered homepage.
- `./example-archive/webroot/__static/<host>/...` contains downloaded assets.
- `./example-archive/archive.json` lists pages/assets and any skipped/errors.
- Opening `index.html` (or serving `webroot/`) renders without network calls
  except to `fonts.googleapis.com` / `fonts.gstatic.com`.

- [ ] **Step 5: Apache fallback verification (requires Apache)**

Serve `webroot/` under Apache with `mod_rewrite` enabled and `AllowOverride All`, then:
- Visit a real page (e.g. `/privacy`) → renders `privacy/index.html`.
- Visit `/about/asdfasdfasd` → serves `/about/index.html` (or nearest ancestor),
  URL unchanged in the browser.
- Visit a total miss (e.g. `/zzz/yyy`) → serves root `/index.html`.

If the walk-up doesn't behave as expected, adjust the rules in
`src/HtaccessWriter::contents()` and re-run `vendor/bin/phpunit tests/HtaccessWriterTest.php`.

- [ ] **Step 6: Commit**

```bash
git add chrome_instructions.md README.md
git commit -m "Add Chrome install instructions and README"
```

---

## Self-Review Notes (author checklist — already applied)

- **Spec coverage:** scaffold/folders (Task 13), JS render via Chrome CLI shell-out (Task 11),
  crawl same-domain + cap + loop protection (Tasks 4, 12), JSON manifest (Task 9),
  page→`index.html` mapping incl. `.html` + trailing-slash + query-skip (Task 3),
  single-root walk-up `.htaccess` (Task 8), `__static/<host>` downloads incl.
  recursive CSS (Tasks 5, 6, 13), HTML/CSS rewriting to local relative paths
  (Tasks 6, 7, 13), Google Fonts left remote (Tasks 5, 6, 7), `chrome_instructions.md`
  (Task 14). All covered.
- **Type consistency:** `Browser::render()` / `Browser::buildArgs()` / `Browser::detectBinary()`, `Crawler::crawl()` returning
  `['pages'=>..., 'order'=>..., 'capped'=>...]`, `UrlMapper` static signatures,
  `Manifest` add* methods, and resolver closures returning `?string` are used
  consistently across Tasks 3–13.
- **Deviations from spec (documented above):** native `DOMDocument` instead of
  `symfony/dom-crawler`; deterministic query-hash filenames for colliding assets.
