# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP 8.1+ CLI that crawls a JavaScript-rendered website with headless Chrome and writes a self-contained, deployable static copy. No runtime Composer packages — Composer is used only for PSR-4 autoload (`ArchiveSite\` → `src/`) and PHPUnit.

## Commands

```bash
composer install                                   # autoload + phpunit
php archiveSite.php <folder> <url>                 # e.g. php archiveSite.php ./archives/example https://example.com
vendor/bin/phpunit                                 # all tests
vendor/bin/phpunit tests/UrlMapperTest.php         # one file
vendor/bin/phpunit --filter test_name_here         # one test
```

`phpunit.xml` sets `failOnWarning="true"`. There is no linter configured; CI (`.github/workflows/ci.yml`) runs `php -l` over `src/`, `tests/` and `archiveSite.php` plus PHPUnit on PHP 8.1–8.3.

Chrome must be installed (see `chrome_instructions.md`). `CHROME_BIN` overrides detection; otherwise `Browser::DEFAULT_BINARIES` lists the paths probed. Unit tests run offline and never launch Chrome — the renderer is only exercised by a real archive run.

## Architecture

`src/ArchiveSite.php::run()` is the orchestrator and runs six numbered phases, printing verbose progress (stdout for progress, stderr for errors/warnings):

1. Scaffold `<folder>/webroot/__static/`.
2. **Crawl** — `Crawler` does a BFS from the seed URL, calling `Browser::render()` per page and extracting links from the *rendered* DOM. Scope = same registrable domain (naive last-two-labels heuristic, so subdomains are included). Query-string URLs are skipped. Capped at `maxPages` (500).
3. **Save HTML** — `UrlMapper::pageUrlToPath()`: `/` → `index.html`, `*.html` keeps its name, anything else → `name/index.html`.
4. **Download assets** — `AssetCollector` gathers asset URLs from every page; `Downloader` (curl) saves each to `__static/<host>/<path>` via `UrlMapper::assetUrlToStaticPath()`. Downloaded CSS is scanned with `CssRewriter::extractUrls()` and nested `url()`/`@import` targets are queued (work-queue loop, deduped by `$seen`).
5. **Rewrite** — CSS then HTML. Both rewriters take a resolver callback `absUrl → replacement|null`; `null` means "leave untouched", which is how Google Fonts and failed downloads keep their remote URLs. `HtmlRewriter` also takes a `$linkResolver` that turns in-scope `<a href>`s into root-relative paths (`/aboutus`).
6. Write `webroot/.htaccess` (`HtaccessWriter`) and `<folder>/archive.json` (`Manifest`: pages, assets, skipped, errors).

Cross-cutting details that are easy to get wrong:

- **Relative refs resolve against the page's directory**, not the page URL: use `UrlMapper::pageBaseUrl()` (a page saved at `blog/index.html` is served at `/blog/`). Asset hrefs in output are relative (`UrlMapper::relativeHref`), page links are root-relative.
- **Path safety**: `assetUrlToStaticPath` collapses dot segments, and `ArchiveSite` re-checks that every destination is under `__static/` with no `../` before writing.
- **`Browser::render()`**: `--headless=new --dump-dom` does not self-exit here, so stdout goes to a temp *file* (a pipe never hits EOF because Chrome helpers inherit the fd), the file is polled until its size is stable, then Chrome is killed. Each render uses a throwaway profile dir.
- **`HtmlRewriter`** encodes non-ASCII to numeric entities before `DOMDocument::loadHTML` (libxml assumes Latin-1) and decodes after save — keep this when touching DOM parsing.
- **`.htaccess`** serves real files, else the nearest ancestor `index.html` via one rule per depth (up to `MAX_DEPTH` = 15), else root `index.html`. It deliberately avoids a restart/loop flag, which causes a 500 in `.htaccess` context. Deploy needs Apache with `mod_rewrite` + `AllowOverride All`.

## Repo notes

- Design spec and implementation plan (with the confirmed requirements/decisions) live in `docs/superpowers/specs/` and `docs/superpowers/plans/`.
- Archive output is gitignored by rule (`/archives/`, `**/webroot/`, `**/archive.json`). Never commit run output; write test runs under `./archives/`.
- Runtime output is intentionally verbose (per-page / per-asset lines and phase headers); keep new output in that style using `ArchiveSite::out()` / `err()`.
