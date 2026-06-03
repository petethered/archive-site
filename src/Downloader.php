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
