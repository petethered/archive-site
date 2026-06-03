# Archive Site — Design Spec

**Date:** 2026-06-03
**Status:** Approved (design), pending implementation plan

## Purpose

A PHP CLI tool that takes a live website URL, crawls it (including
JavaScript-rendered content) via a headless browser, downloads its pages and
static assets, and produces a self-contained, deployable static HTML site. The
archived site must render without contacting any external host **except Google
Web Fonts**.

Primary motivating use case: archiving sites of a shutting-down startup (e.g.
`https://example.com`) into a deployable form.

## Usage

```
php archiveSite.php <folder> <url>
```

- `<folder>` — output directory. Created if it does not exist.
- `<url>`    — the site to archive (e.g. `https://example.com`).

## Confirmed Requirements & Decisions

1. **Rendering:** Sites are JavaScript-rendered. The tool drives **headless
   Chrome by shelling out to the Chrome binary** per page
   (`--headless=new --no-sandbox --disable-gpu --user-data-dir=<temp>
   --window-size=1280,20000 --virtual-time-budget=8000
   --run-all-compositor-stages-before-draw --dump-dom <url>`), capturing the
   rendered DOM from stdout. No Composer/browser library dependency. A tall
   window height puts most lazy-loaded (IntersectionObserver) content "in
   viewport" so it loads without real scrolling; `--virtual-time-budget` lets
   JS/network settle. Needs a Chrome/Chromium binary installed. (Chosen over the
   `chrome-php` DevTools library: simpler, fewer moving parts, and the exact
   sandbox-compatible flag set is known-good.)
2. **Crawl scope:** Same **registrable domain** (follow `example.com` and
   its subdomains like `www.`, `blog.`; do not follow external sites). Static
   assets are still downloaded from wherever they are hosted (e.g.
   `cdn.example.net`). Loop protection (visited-set) and a configurable
   max-page cap are always enforced.
3. **Page URL → file path:**
   - Query-string pages (e.g. `/search?q=faith`) are **skipped** and logged.
   - URLs already ending in `.html` are kept as-is (`about.html`).
   - Trailing slashes are normalized/deduped (`/privacy` == `/privacy/` →
     `privacy/index.html`).
   - Homepage → `index.html`.
4. **Static assets:**
   - CSS is parsed for nested `url()` and `@import` references; those assets are
     downloaded and their references rewritten (recursively until none remain).
   - Asset filenames strip the query string; on a genuine collision (same path,
     different content) a short content hash is appended.
   - **Google Fonts exception:** `fonts.googleapis.com` and `fonts.gstatic.com`
     URLs (both the CSS and the font files) are left untouched — still loaded
     from Google at render time. Everything else is localized.
5. **HTML rewriting:** Asset references are rewritten to **relative** paths from
   each file to `__static/`, so the archive works regardless of the domain it is
   later deployed under. Google Fonts URLs are left untouched. Internal
   navigation links (`<a href>` to the same registrable domain, non-query) are
   rewritten to **root-relative** paths (e.g. `/aboutus`), preserving any
   fragment, so the archive is click-through navigable at any deploy domain (the
   `.htaccess` serves `/aboutus` → `/aboutus/index.html`). Links to external
   domains and to query-string pages are left absolute.
6. **`.htaccess`:** A **single root** `.htaccess` using `mod_rewrite`. Real
   files/dirs are served as-is. On a miss, it performs an internal rewrite (URL
   unchanged in the browser) walking **up** the path to the nearest ancestor
   directory that has an `index.html`, ultimately falling back to the root
   `index.html`.

## Directory Layout

```
<folder>/
  archive.json                 # manifest / run-state file
  webroot/
    index.html                 # homepage
    privacy/index.html
    about.html                 # URLs already ending in .html kept as-is
    .htaccess                  # single root, walk-up fallback
    __static/
      cdn.example.net/assets/app.js
      cdn.example.com/img/logo.png
      ...
```

## Architecture & Components

Single CLI entry point (`archiveSite.php`) orchestrating focused classes under
`src/` (Composer PSR-4 autoload). Each unit has one responsibility and a clear
interface.

- **`ArchiveSite` (orchestrator)** — parses args, creates the directory
  scaffold, runs the pipeline, writes the manifest.
- **`Browser`** — shells out to the Chrome binary (`--dump-dom` with the
  sandbox-compatible flag set above) and returns the rendered HTML from stdout.
  Resolves the Chrome binary path (auto-detect with override).
- **`Crawler`** — BFS over same-registrable-domain pages. Maintains the
  visited-set and max-page cap, extracts internal links, normalizes/dedupes
  URLs, skips query-string pages. Produces the page list.
- **`AssetCollector`** — finds static assets per rendered page (`<img src>`,
  `<link href>`, `<script src>`, `srcset`, inline `style`, `<style>` blocks) plus
  nested CSS `url()`/`@import` references (recursively). Excludes Google Fonts
  hosts.
- **`Downloader`** — fetches assets via cURL into `__static/<host>/<path>`,
  strips query strings for filenames (hash-suffix on real collisions), retains
  content for CSS re-parsing.
- **`UrlMapper`** — pure functions: page URL → on-disk path; asset URL →
  `__static/<host>/<path>` + the relative href from a given page.
- **`HtmlRewriter`** — rewrites asset references in saved HTML to local
  `__static` paths (relative to each file's depth); leaves Google Fonts alone.
- **`CssRewriter`** — same for `url()`/`@import` inside downloaded CSS.
- **`HtaccessWriter`** — writes the single root `.htaccess`.
- **`Manifest`** — the JSON state/record file.

**Dependencies:** none beyond the PHP runtime and a Chrome/Chromium binary.
Native `DOMDocument`/`DOMXPath` (parsing/rewriting), `ext-curl` (downloads),
`proc_open` (Chrome shell-out). No third-party Composer packages required at
runtime (PHPUnit is dev-only).

## Pipeline (Run Order)

1. **Scaffold** — make `<folder>`, `webroot`, `webroot/__static`.
2. **Crawl** — start at the input URL. For each page: `Browser` renders → save
   rendered HTML to its mapped path → `Crawler` extracts/queues new internal
   links → `AssetCollector` records the page's assets. Loop until the queue is
   empty or the max-page cap is hit.
3. **Download assets** — `Downloader` pulls every collected asset. Downloaded
   CSS is re-scanned for nested `url()`/`@import`; new assets are queued and
   downloaded (recursive until none remain). Google Fonts hosts never
   downloaded.
4. **Rewrite** — `HtmlRewriter` rewrites every saved HTML file's asset refs to
   relative local paths; `CssRewriter` rewrites refs inside downloaded CSS.
5. **.htaccess** — `HtaccessWriter` writes the single root file.
6. **Finalize** — write `archive.json`.

## `.htaccess` Rule

```apache
RewriteEngine On
RewriteBase /

# 1. Real file or directory -> serve it
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# 2. Serve the nearest existing ancestor's index.html. One rule per ancestor depth
#    (generated 1..15), each stripping one more trailing segment; first match wins.
RewriteCond %{DOCUMENT_ROOT}/$1/index.html -f
RewriteRule ^(.+)/[^/]+/?$ /$1/index.html [L]
RewriteCond %{DOCUMENT_ROOT}/$1/index.html -f
RewriteRule ^(.+)/[^/]+/[^/]+/?$ /$1/index.html [L]
#    ... through depth 15 ...

# 3. No ancestor matched -> serve the root index.html
RewriteRule ^ /index.html [L]
```

Behavior (verified against real Apache 2.4 with `mod_rewrite` + `AllowOverride All`):
- `/about/asdfasdfasd` → `/about/index.html` (200)
- `/about/team/deeper/xyz` (no `/about/team/…`) → climbs to `/about/index.html` (200)
- Total miss (`/zzz/yyy`) → `/index.html` (200)

**Note:** the original single-rule `[N]` (restart-and-strip) design was replaced after
real-Apache testing: in `.htaccess` context the `[N]` flag re-prefixes `RewriteBase`
each restart, growing the URI until Apache aborts with a 500. The loop-free,
one-rule-per-depth form above avoids that. A real directory path like `/about` is
first handled by `mod_dir`'s `DirectorySlash` (a 301 to `/about/`), which then serves
`/about/index.html` — so internal links use root-relative `/about` and resolve in one redirect.

## `archive.json` Manifest

Written at the end; doubles as the run record.

```json
{
  "source": "https://example.com",
  "startedAt": "...",
  "finishedAt": "...",
  "pages":   [{ "url": "...", "path": "privacy/index.html", "status": "fetched" }],
  "assets":  [{ "url": "...", "host": "...", "path": "__static/.../app.js", "status": "downloaded" }],
  "skipped": [{ "url": "...?q=x", "reason": "query-string" }],
  "errors":  [{ "url": "...", "reason": "timeout" }]
}
```

## Error Handling

- **Page render failure** → logged in `errors`, crawl continues.
- **Asset download failure** → logged in `errors`; its reference is left pointing
  at the original remote URL (graceful degradation over a broken local link).
- **Chrome launch failure** → fatal, with a clear message pointing to
  `chrome_instructions.md`.
- **Max-page cap reached** → warn and finalize with what was gathered.

## Testing Approach

- **Unit tests** for pure logic — `UrlMapper`, `HtmlRewriter`, `CssRewriter`,
  link normalization — using HTML/CSS fixtures (no network).
- **Manual integration** of the Chrome-dependent `Browser`/`Crawler` against a
  real site to validate output.

## Deliverables

- `archiveSite.php` — CLI entry point
- `src/*` — component classes
- `composer.json` — dependencies + PSR-4 autoload
- `chrome_instructions.md` — Chrome/Chromium install for Ubuntu and macOS
- `README.md` — usage
```

