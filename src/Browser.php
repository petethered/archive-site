<?php

declare(strict_types=1);

namespace ArchiveSite;

final class Browser
{
    /** Environment variable that overrides auto-detection with an explicit binary path. */
    public const ENV_VAR = 'CHROME_BIN';

    public const DEFAULT_BINARIES = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];

    private string $binary;

    public function __construct(
        ?string $chromeBinary = null,
        private readonly int $virtualTimeBudgetMs = 8000,
        private readonly int $processTimeoutSeconds = 60,
    ) {
        $this->binary = self::resolveBinary($chromeBinary, self::DEFAULT_BINARIES);
    }

    /** The Chrome/Chromium binary this instance launches. */
    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * Pick the binary to launch: an explicit override (the CLI passes CHROME_BIN) must exist and
     * be executable; otherwise the first executable candidate wins.
     *
     * @param string[] $candidates
     * @return string path of the binary to launch
     * @throws \RuntimeException if the override is unusable or no candidate is executable
     */
    public static function resolveBinary(?string $override, array $candidates): string
    {
        if ($override !== null && $override !== '') {
            if (!is_file($override) || !is_executable($override)) {
                throw new \RuntimeException(
                    "Chrome binary override is not an executable file: {$override}"
                );
            }
            return $override;
        }
        $detected = self::detectBinary($candidates);
        if ($detected === null) {
            throw new \RuntimeException(
                'No Chrome/Chromium binary found. Set ' . self::ENV_VAR . ' or see chrome_instructions.md.'
            );
        }
        return $detected;
    }

    /**
     * @param string[] $candidates
     * @return string|null first executable candidate
     */
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

        // Write stdout to a temp file rather than a pipe: headless Chrome spawns helper
        // processes that inherit the stdout fd, so a pipe never reaches EOF and a reader
        // would block forever. stderr is discarded (Chrome floods it with sandbox noise).
        $outFile = $profile . '.dom.html';
        $descriptors = [
            1 => ['file', $outFile, 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open($args, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->rmrf($profile);
            @unlink($outFile);
            throw new \RuntimeException("Failed to launch Chrome: {$this->binary}");
        }

        // `--headless=new` Chrome writes the full DOM via --dump-dom but does NOT self-exit
        // in this environment. So poll the output file until it is non-empty and its size has
        // stabilized (the dump is written in one shot once load / virtual-time-budget settles),
        // then terminate Chrome ourselves. Bail with a timeout if nothing stabilizes.
        $deadline = time() + $this->processTimeoutSeconds;
        $lastSize = -1;
        $stableCount = 0;
        $done = false;
        while (time() <= $deadline) {
            usleep(250_000);
            clearstatcache(true, $outFile); // filesize() caches stat results; force a fresh read
            $size = is_file($outFile) ? (int) filesize($outFile) : 0;
            if ($size > 0 && $size === $lastSize) {
                if (++$stableCount >= 3) { // ~0.75s stable, guards against a chunked dump
                    $done = true;
                    break;
                }
            } else {
                $stableCount = 0;
            }
            $lastSize = $size;

            $status = proc_get_status($proc);
            if (!$status['running']) { // Chrome exited on its own (e.g. old headless)
                $done = true;
                break;
            }
        }

        proc_terminate($proc, 9);
        proc_close($proc);

        $html = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $this->rmrf($profile);
        @unlink($outFile);

        if (!$done || trim($html) === '') {
            throw new \RuntimeException("Chrome produced no usable output for {$url} within {$this->processTimeoutSeconds}s.");
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
