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
