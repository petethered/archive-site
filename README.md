# archiveSite

Crawl a JavaScript-rendered website with headless Chrome and produce a
self-contained, deployable static site. The archived site renders offline —
all assets are localized under `__static/<host>/...` and internal links are made
root-relative — **except Google Web Fonts**, which are intentionally still
loaded from Google.

## Requirements

- PHP 8.1+ with `ext-dom`, `ext-curl`, `ext-json`
- Composer (autoload only — no runtime packages)
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
- **Internal `<a href>` links** (same domain, non-query) are rewritten to
  root-relative paths (e.g. `/aboutus`) so navigation works at any deploy
  domain. External and query-string links stay absolute.
- **Assets** (CSS, JS, images, fonts, and nested CSS `url()`/`@import` targets)
  are downloaded under `__static/<host>/<path>` and references rewritten to
  relative paths.
- **Google Fonts** (`fonts.googleapis.com`, `fonts.gstatic.com`) are left
  pointing at Google.
- A failed asset download leaves the original remote URL in place (graceful
  degradation).

## Deploying

Serve `webroot/` with Apache (`mod_rewrite` + `AllowOverride All`) so the root
`.htaccess` takes effect and internal links resolve. Any static host works for
rendering individual pages, but the 404 walk-up fallback requires Apache
`.htaccess` support.

## Tests

```bash
vendor/bin/phpunit
```

The Chrome-dependent renderer is exercised by a real run; the pure logic
(URL/path mapping, link & asset extraction, HTML/CSS rewriting, htaccess,
manifest) is covered by offline unit tests.
