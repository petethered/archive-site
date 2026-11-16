# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-16

First public release.

### Added
- `archiveSite.php <folder> <url>`: crawls a JavaScript-rendered site with
  headless Chrome and writes a static copy under `<folder>/webroot/`.
- Same-domain crawl (subdomains included), capped at 500 pages.
- Asset download for CSS, JS, images and fonts into `__static/<host>/...`,
  including assets referenced from inside CSS (`url()`, `@import`).
- Rewriting of asset references to relative paths and internal links to
  root-relative paths, so the copy works at any deploy domain.
- A root `.htaccess` that serves the nearest parent folder's `index.html` for
  missing paths.
- `archive.json` manifest of pages, assets, skipped links and errors.
- `CHROME_BIN` to choose the Chrome binary; auto-detection covers Chrome and
  Chromium on macOS and Linux, including the Ubuntu snap.
- Verbose, per-page and per-asset progress output.
- Offline PHPUnit suite and GitHub Actions CI on PHP 8.1, 8.2 and 8.3.

### Known limits
- Query-string pages are skipped, not archived.
- The domain rule uses the last two labels of the host, so it is too broad
  for two-part suffixes such as `co.uk`.
- Google Fonts and assets that fail to download stay remote.
- Each page is rendered once with no interaction, so content that needs a
  click, or more than about 8 seconds of page time, is not captured.

[0.1.0]: https://github.com/petethered/archive-site/releases/tag/v0.1.0
