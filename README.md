# archiveSite

[![CI](https://github.com/petethered/archive-site/actions/workflows/ci.yml/badge.svg)](https://github.com/petethered/archive-site/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Crawl a JavaScript-rendered website with headless Chrome and produce a
self-contained, deployable static site. Sites that build their pages in the
browser can't be archived by fetching their HTML, so archiveSite lets Chrome run
each page's JavaScript, saves the rendered DOM, downloads every asset it
references, and rewrites the links so the copy renders offline. Upload the
output folder to an Apache host and it works as-is: the generated `.htaccess`
handles clean URLs and missing pages. The one deliberate exception to "offline"
is **Google Web Fonts**, which are still loaded from Google.

## Requirements

- PHP 8.1+ with `ext-curl`, `ext-dom`, `ext-json`, `ext-libxml`, `ext-mbstring`
- Composer (autoload only — no runtime packages)
- Chrome or Chromium (see [`chrome_instructions.md`](chrome_instructions.md))

## Install

```bash
git clone https://github.com/petethered/archive-site.git
cd archive-site
composer install
```

## Usage

```bash
php archiveSite.php <folder> <url>
```

Example:

```bash
php archiveSite.php ./archives/example https://example.com
```

Chrome is found automatically in the usual install locations. To use a
different binary, set `CHROME_BIN`:

```bash
CHROME_BIN=/path/to/chrome php archiveSite.php ./archives/example https://example.com
```

The run prints each phase as it goes: every page rendered, every query-string
link skipped, every asset downloaded or failed, and a summary at the end.

## Use responsibly

archiveSite is for archiving sites you own or have permission to copy. It
crawls the starting site's domain (and its subdomains) only, skips query-string
URLs, and stops at 500 pages.

## Output

```
archives/example/
  archive.json          # manifest: pages, assets, skipped links, errors
  webroot/              # deploy this directory
    index.html
    privacy/index.html
    about.html          # pages already ending in .html keep their name
    .htaccess           # serves the nearest ancestor index.html on a miss
    __static/<host>/... # every downloaded asset, grouped by the host it came from
```

- **Pages** are saved as `index.html` for the home page, `name/index.html` for
  most pages, and under their own name if the URL already ends in `.html`.
- **Assets** (CSS, JS, images, fonts, and nested CSS `url()`/`@import`
  targets) are downloaded under `__static/<host>/<path>`, and references to them
  are rewritten to relative paths.
- **Internal `<a href>` links** (same domain, no query string) are rewritten to
  root-relative paths (e.g. `/aboutus`) so navigation works at any deploy
  domain. External and query-string links stay absolute.
- **`.htaccess`** serves real files as-is. For any other path it serves the
  `index.html` of the nearest parent folder that has one, falling back to the
  home page, so old deep links still land somewhere sensible.
- **`archive.json`** records the source URL, start and finish times, every page
  and asset with its local path, each skipped link, and each error.

## Deploying

Serve `webroot/` with Apache (`mod_rewrite` + `AllowOverride All`) so the root
`.htaccess` takes effect and internal links resolve. Any static host works for
rendering individual pages, but the missing-page fallback requires Apache
`.htaccess` support.

## Limits

- **500 pages per run.** The cap is a constructor default on `ArchiveSite`, not
  a command-line flag. When it is hit the run warns and finishes with the pages
  it has.
- **Query-string pages are not archived** (e.g. `/search?q=x`). They are listed
  under `skipped` in `archive.json`, and links to them stay absolute.
- **The domain rule is "last two labels of the host".** `www.example.com` and
  `blog.example.com` both count as `example.com`, which is the intent. It is
  wrong for two-part public suffixes: for `shop.example.co.uk` the scope becomes
  all of `co.uk`, and on shared hosts like `*.github.io` it covers every site on
  that host.
- **Google Fonts stay remote.** `fonts.googleapis.com` and `fonts.gstatic.com`
  are never downloaded.
- **Failed downloads stay remote.** If an asset can't be fetched, its original
  URL is left in place and the failure is recorded in `archive.json`.
- **One render per page, no interaction.** Content that appears only after a
  click, or after more than about 8 seconds of page time, is not captured.

## How it's tested

```bash
vendor/bin/phpunit
```

The PHPUnit suite is fully offline and never launches Chrome. It covers the
pure logic: URL and path mapping, link and asset extraction, HTML and CSS
rewriting, the `.htaccess` rules, Chrome binary resolution, and the manifest.
`failOnWarning` is on, so a PHP warning fails the run. CI runs `php -l` and the
suite on PHP 8.1, 8.2 and 8.3. The Chrome renderer itself is exercised by real
archive runs.

## How it was built

archiveSite was designed from a written spec
([`docs/superpowers/specs/2026-06-03-archive-site-design.md`](docs/superpowers/specs/2026-06-03-archive-site-design.md))
and an implementation plan, then built test-first with
[Claude Code](https://claude.com/claude-code) as the coding agent.
[`CLAUDE.md`](CLAUDE.md) is the project memory the agent works from: commands,
the pipeline, and the details that are easy to break. The commit history
follows that sequence.

Some of the problems that came up along the way:

- **Headless Chrome never exits.** With `--headless=new --dump-dom`, Chrome
  writes the DOM and keeps running, and its helper processes hold stdout open,
  so reading a pipe blocks forever. The renderer sends output to a temp file,
  polls it until its size stops changing, then kills Chrome.
- **UTF-8 through `DOMDocument`.** libxml parses HTML as Latin-1, which mangled
  non-ASCII text when pages were rewritten. The rewriter converts non-ASCII
  characters to numeric entities before parsing and converts them back after.
- **An `.htaccess` fallback without a loop.** The first design walked up the
  path with a single rule and the `[N]` restart flag. Tested against real
  Apache, each restart re-added the `RewriteBase` and the URI grew until
  Apache returned a 500. The final version generates one rule per folder depth instead.

## License

[MIT](LICENSE) © 2026 Peter Azuolas
