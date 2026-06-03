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

    /**
     * BFS from the seed using the Browser. Returns
     * [ 'pages' => [normUrl => html], 'order' => [normUrl,...], 'capped' => bool ].
     * Records render failures via $onError(url, reason).
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

            // Resolve relative links against the page's directory base (/blog/, not /blog).
            foreach ($this->extractLinks($html, UrlMapper::pageBaseUrl($url)) as $link) {
                if (!isset($visited[$link])) {
                    $queue[] = $link;
                }
            }
        }

        return ['pages' => $pages, 'order' => $order, 'capped' => $queue !== []];
    }
}
