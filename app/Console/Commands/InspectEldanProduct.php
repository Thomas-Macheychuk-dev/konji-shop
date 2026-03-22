<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use App\Services\Eldan\EldanProductPayloadExtractor;
use App\Services\Eldan\EldanProductNormalizer;
use App\Services\Eldan\EldanProductImporter;

class InspectEldanProduct extends Command
{
    protected $signature = 'eldan:inspect {url} {--save-html : Save fetched HTML to storage/app/eldan-inspect.html}';
    protected $description = 'Inspect a single Eldan product page and dump extracted fields';

    public function handle(): int
    {
        $url = (string) $this->argument('url');

        $this->info("Fetching: {$url}");

        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            ])
            ->get($url);

        if (! $response->successful()) {
            $this->error("Request failed with status {$response->status()}");

            return self::FAILURE;
        }

        $html = $response->body();

        $extractor = app(EldanProductPayloadExtractor::class);
        $payload = $extractor->extract($html);

        $normalizer = app(EldanProductNormalizer::class);
        $normalized = $normalizer->normalize($payload);

        if ($this->option('save-html')) {
            file_put_contents(storage_path('app/eldan-inspect.html'), $html);
            $this->info('Saved raw HTML to storage/app/eldan-inspect.html');
        }

        $this->line('Downloaded HTML size: ' . strlen($html) . ' bytes');

        $crawler = new Crawler($html);

        $needles = [
            'short_description',
            'url_key',
            'base_image',
            'selected_configurable_option',
            'possibleOptionVariant',
            'product_id',
            'sku',
            'price',
            'super_attributes',
            'configurable',
            'variants',
            'gallery_images',
            'images',
            'childAttributes',
            'this.config',
            'x-data',
        ];

        $data = [
            'source_url' => $url,
            'title_tag' => $this->extractTitle($crawler),
            'h1' => $this->extractFirstText($crawler, 'h1'),
            'meta_description' => $this->extractMetaDescription($crawler),
            'meta_og_title' => $this->extractMetaProperty($crawler, 'og:title'),
            'meta_og_description' => $this->extractMetaProperty($crawler, 'og:description'),
            'meta_og_image' => $this->extractMetaProperty($crawler, 'og:image'),
            'meta_og_url' => $this->extractMetaProperty($crawler, 'og:url'),
            'canonical' => $this->extractCanonical($crawler),
            'images' => $this->extractRelevantImages($crawler),
            'json_ld' => $this->extractJsonLd($crawler),
            'raw_price_candidates' => $this->extractPriceCandidates($html),
            'breadcrumbs' => $this->extractBreadcrumbs($crawler),
            'keyword_counts' => $this->countNeedles($html, $needles),
            'raw_contexts' => $this->extractContexts($html, $needles, 700),
            'best_guess' => [
                'name' => $this->extractMetaProperty($crawler, 'og:title')
                    ?: $this->extractFirstText($crawler, 'h1')
                        ?: $this->extractTitle($crawler),
                'description' => $this->extractMetaProperty($crawler, 'og:description')
                    ?: $this->extractMetaDescription($crawler),
                'main_image' => $this->extractMetaProperty($crawler, 'og:image'),
                'canonical_url' => $this->extractCanonical($crawler) ?: $url,
                'price_candidates' => $this->extractPriceCandidates($html),
            ],
            'parsed_payload' => $payload,
            'normalized' => $normalized,
        ];

        $this->newLine();
        $this->info('=== EXTRACTED DATA ===');
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($this->confirm('Import this product into database?', false)) {
            $importer = app(EldanProductImporter::class);
            $product = $importer->import($normalized);

            $this->info("Imported product ID: {$product->id}");
        }

        return self::SUCCESS;
    }

    private function extractTitle(Crawler $crawler): ?string
    {
        return $this->extractFirstText($crawler, 'title');
    }

    private function extractMetaDescription(Crawler $crawler): ?string
    {
        return $this->extractMetaByName($crawler, 'description');
    }

    private function extractMetaByName(Crawler $crawler, string $name): ?string
    {
        try {
            $node = $crawler->filter(sprintf('meta[name="%s"]', $name));

            if ($node->count() === 0) {
                return null;
            }

            return $this->normalizeWhitespace((string) $node->first()->attr('content'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractMetaProperty(Crawler $crawler, string $property): ?string
    {
        try {
            $node = $crawler->filter(sprintf('meta[property="%s"]', $property));

            if ($node->count() === 0) {
                return null;
            }

            return $this->normalizeWhitespace((string) $node->first()->attr('content'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractCanonical(Crawler $crawler): ?string
    {
        try {
            $node = $crawler->filter('link[rel="canonical"]');

            if ($node->count() === 0) {
                return null;
            }

            return trim((string) $node->first()->attr('href'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractFirstText(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                return null;
            }

            return $this->normalizeWhitespace($node->first()->text());
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractRelevantImages(Crawler $crawler): array
    {
        $images = [];

        try {
            $crawler->filter('img')->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src') ?: $node->attr('data-src') ?: $node->attr('data-srcset');

                if (! $src) {
                    return;
                }

                $src = trim($src);

                if ($this->shouldKeepImage($src)) {
                    $images[] = $src;
                }
            });
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique($images));
    }

    private function shouldKeepImage(string $src): bool
    {
        $srcLower = mb_strtolower($src);

        $disallowedFragments = [
            'facebook.com/tr?',
            '/themes/',
            '.svg',
            'search-',
            'heart-',
            'cart-',
            'check-',
            'plus-',
            'minus-',
            'user-',
            'logo',
            'icon',
        ];

        foreach ($disallowedFragments as $fragment) {
            if (str_contains($srcLower, $fragment)) {
                return false;
            }
        }

        return str_starts_with($srcLower, 'http://') || str_starts_with($srcLower, 'https://');
    }

    private function extractJsonLd(Crawler $crawler): array
    {
        $items = [];

        try {
            $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$items) {
                $raw = trim($node->text(''));

                if ($raw === '') {
                    return;
                }

                $decoded = json_decode($raw, true);

                $items[] = [
                    'raw' => $raw,
                    'decoded' => $decoded,
                ];
            });
        } catch (\Throwable) {
            return [];
        }

        return $items;
    }

    private function extractPriceCandidates(string $html): array
    {
        preg_match_all('/\d+[.,]\d{2}(?:\h|&nbsp;|\x{00A0})?(?:zł|PLN)/ui', $html, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function extractBreadcrumbs(Crawler $crawler): array
    {
        $items = [];

        $selectors = [
            '[aria-label="breadcrumb"] a',
            '.breadcrumb a',
            '.breadcrumbs a',
            'nav[aria-label="breadcrumb"] a',
        ];

        foreach ($selectors as $selector) {
            try {
                $nodes = $crawler->filter($selector);

                if ($nodes->count() === 0) {
                    continue;
                }

                $nodes->each(function (Crawler $node) use (&$items) {
                    $text = $this->normalizeWhitespace($node->text());

                    if ($text !== null && $text !== '') {
                        $items[] = $text;
                    }
                });

                if (! empty($items)) {
                    break;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values(array_unique($items));
    }

    private function extractContexts(string $html, array $needles, int $radius = 800): array
    {
        $results = [];

        foreach ($needles as $needle) {
            $offset = 0;
            $matches = [];

            while (($pos = stripos($html, $needle, $offset)) !== false) {
                $start = max(0, $pos - $radius);
                $end = min(strlen($html), $pos + strlen($needle) + $radius);
                $chunk = substr($html, $start, $end - $start);

                $matches[] = $this->trimLargeWhitespace($chunk);

                $offset = $pos + strlen($needle);

                if (count($matches) >= 3) {
                    break;
                }
            }

            $results[$needle] = $matches;
        }

        return $results;
    }

    private function countNeedles(string $html, array $needles): array
    {
        $counts = [];

        foreach ($needles as $needle) {
            $counts[$needle] = substr_count(mb_strtolower($html), mb_strtolower($needle));
        }

        return $counts;
    }

    private function trimLargeWhitespace(string $value): string
    {
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);

        return trim($value);
    }

    private function normalizeWhitespace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $value);
    }
}
