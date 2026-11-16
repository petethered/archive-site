# Installing Chrome / Chromium for archiveSite

`archiveSite.php` drives a headless Chrome by shelling out to the Chrome binary
once per page (`--dump-dom`) and reading the rendered DOM. You need a Chrome or
Chromium binary installed.

## macOS

Option A — Google Chrome (GUI installer):
1. Download from https://www.google.com/chrome/ and install to `/Applications`.
2. The tool auto-detects `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`
   (and `/Applications/Chromium.app/...` for Chromium).

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

The tool runs Chrome once per page and reads the rendered DOM from a temp file.
It applies these flags automatically (you don't pass them):

```
--headless=new --no-sandbox --disable-gpu --user-data-dir=<fresh temp dir>
--window-size=1280,20000 --virtual-time-budget=8000
--run-all-compositor-stages-before-draw --dump-dom <url>
```

- `--no-sandbox` is required when Chrome runs nested inside another sandbox
  (CI, or a restricted launch context); the enclosing sandbox stays the
  enforcing boundary.
- The tall window height makes most lazy-loaded (IntersectionObserver) content
  load without scrolling.
- `--virtual-time-budget` gives JS/network time to settle before the DOM dump.
- `--headless=new` Chrome does not always self-exit after `--dump-dom`; the tool
  waits for the dumped output to stabilize, then terminates Chrome itself.

## Telling archiveSite where Chrome is

Auto-detection checks these paths in order and uses the first executable one:

```
/Applications/Google Chrome.app/Contents/MacOS/Google Chrome
/Applications/Chromium.app/Contents/MacOS/Chromium
/usr/bin/google-chrome
/usr/bin/google-chrome-stable
/usr/bin/chromium
/usr/bin/chromium-browser
/snap/bin/chromium
```

If your binary lives elsewhere, set `CHROME_BIN`:

```bash
CHROME_BIN=/opt/chrome/chrome php archiveSite.php ./archives/example https://example.com
```

An explicit `CHROME_BIN` must be the path to an executable file (a bare command
name such as `google-chrome` is not looked up on `PATH`); the run stops with an
error before creating any output if it is not. The binary in use is printed in
the run header (`chrome binary : ...`).
