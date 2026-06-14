<?php

declare(strict_types=1);

namespace App\Services\Peruka;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class PerukaProductScraper
{
    private const PERUKA_HOST = 'www.peruka.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 500;

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function scrape(string $url): array
    {
        $productUrl = $this->normalizeProductUrl($url) ?? $url;

        $this->emit('Fetching Peruka product page: '.$productUrl);

        return $this->extract($this->fetchBody($productUrl), $productUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(string $html, string $url): array
    {
        $crawler = new Crawler($html, $url);
        $canonicalUrl = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href');
        $canonicalUrl = is_string($canonicalUrl) ? ($this->normalizeProductUrl($canonicalUrl, $url) ?? $canonicalUrl) : null;
        $sourceUrl = $canonicalUrl ?: ($this->normalizeProductUrl($url) ?? $url);

        $seoTitle = $this->normalizeLabel($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $name = $this->extractName($crawler, $seoTitle);
        $shortDescriptionHtml = $this->innerHtml($crawler, '.description-short');
        $descriptionHtml = $this->innerHtml($crawler, '#description-long[itemprop="description"], #description-long');
        $categories = $this->extractCategories($crawler, $sourceUrl);
        $priceGrossAmount = $this->extractPriceGrossAmount($crawler);
        $stockQuantity = $this->extractStockQuantity($crawler);
        $availabilityLabel = $this->normalizeLabel($crawler->filter('#st_availability_info-value')->first()->text(''));
        $availabilityUrl = $this->firstAttr($crawler, 'meta[itemprop="availability"][content]', 'content');

        return [
            'source' => 'peruka',
            'external_product_id' => $this->extractExternalProductId($crawler, $html),
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => $name,
            'brand' => $this->extractBrand($crawler),
            'category' => $categories !== [] ? end($categories) : null,
            'categories' => $categories,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'short_description' => $this->plainTextFromHtml($shortDescriptionHtml) ?: $seoDescription,
            'short_description_html' => $shortDescriptionHtml,
            'description_html' => $descriptionHtml,
            'price_gross_amount' => $priceGrossAmount,
            'currency' => $this->firstAttr($crawler, 'meta[itemprop="priceCurrency"][content]', 'content') ?? 'PLN',
            'stock_quantity' => $stockQuantity,
            'availability' => $this->normalizeAvailability($availabilityUrl, $availabilityLabel, $stockQuantity),
            'availability_label' => $availabilityLabel !== '' ? $availabilityLabel : null,
            'ean' => $this->firstAttr($crawler, 'meta[itemprop="mpn"][content]', 'content'),
            'sku' => $this->firstAttr($crawler, 'meta[itemprop="sku"][content]', 'content'),
            'images' => $this->extractImages($crawler, $sourceUrl),
            'variant_product_urls' => $this->extractVariantProductUrls($crawler, $sourceUrl),
            'is_medical_device' => $this->isMedicalDevice($crawler),
            'warnings' => $this->warnings($name, $priceGrossAmount, $descriptionHtml),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function extractVariantProductUrls(Crawler $crawler, string $baseUrl): array
    {
        $urls = [];

        try {
            $crawler->filter('#product-colors a[href]')->each(function (Crawler $node) use (&$urls, $baseUrl): void {
                $href = $node->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeProductUrl($href, $baseUrl);

                if ($url !== null) {
                    $urls[$url] = true;
                }
            });
        } catch (Throwable) {
            return [];
        }

        return array_keys($urls);
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isPerukaUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/[a-z0-9][a-z0-9\-]*\.html$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::PERUKA_HOST.$path;
    }

    private function fetchBody(string $url): string
    {
        $this->pauseBeforeRequest();

        $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
            ->timeout($this->timeoutSeconds)
            ->withHeaders($this->headers())
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Peruka product request failed with HTTP '.$response->status().': '.$url);
        }

        return $response->body();
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->requestDelayMilliseconds * 1000);
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($message);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function extractName(Crawler $crawler, string $seoTitle): string
    {
        $name = $this->normalizeLabel($crawler->filter('h1[itemprop="name"], h1')->first()->text(''));

        if ($name !== '') {
            return $name;
        }

        $ogTitle = $this->firstMetaPropertyContent($crawler, 'og:title');

        if (is_string($ogTitle) && trim($ogTitle) !== '') {
            return $this->normalizeLabel($ogTitle);
        }

        return $this->normalizeLabel(preg_replace('/\s*\|\s*Peruka\.pl\s*$/iu', '', $seoTitle) ?: $seoTitle);
    }

    private function extractExternalProductId(Crawler $crawler, string $html): ?string
    {
        foreach ([
            'meta[itemprop="sku"][content]',
            '.product-observe[data-product-observe]',
            'form.basket_add_button[data-product]',
        ] as $selector) {
            $attribute = str_contains($selector, 'data-product-observe') ? 'data-product-observe' : (str_contains($selector, 'data-product]') ? 'data-product' : 'content');
            $value = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (preg_match('/item_id:\s*["\']?(\d+)["\']?/u', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = $this->normalizeLabel($crawler->filter('.producer_name')->first()->text(''));

        if ($brand !== '') {
            return $brand;
        }

        if ($crawler->filter('[itemprop="brand"]')->count() > 0) {
            $brand = $this->normalizeLabel($crawler->filter('[itemprop="brand"]')->first()->text(''));
        }

        return $brand !== '' ? $brand : null;
    }

    /**
     * @return array<int, string>
     */
    private function extractCategories(Crawler $crawler, string $sourceUrl): array
    {
        $categories = [];

        try {
            $crawler->filter('ol.breadcrumb li a[href*="/category/"] span, ol.breadcrumb li a[href*="/category/"]')->each(function (Crawler $node) use (&$categories): void {
                $label = $this->normalizeLabel($node->text(''));

                if ($label !== '') {
                    $categories[$label] = true;
                }
            });
        } catch (Throwable) {
            // Fall back to Google dataLayer category fields below.
        }

        if ($categories !== []) {
            return array_keys($categories);
        }

        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);

        return $path !== '' ? [] : [];
    }

    private function extractPriceGrossAmount(Crawler $crawler): ?float
    {
        $price = $this->firstAttr($crawler, 'meta[itemprop="price"][content]', 'content');

        if (is_string($price) && trim($price) !== '') {
            return (float) str_replace(',', '.', trim($price));
        }

        $label = $this->normalizeLabel($crawler->filter('#st_product_options-price-brutto, .price-line .price')->first()->text(''));

        if ($label === '') {
            return null;
        }

        $number = preg_replace('/[^0-9,\.]/', '', $label) ?: '';
        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? (float) $number : null;
    }

    private function extractStockQuantity(Crawler $crawler): ?int
    {
        $quantity = $this->firstAttr($crawler, '.basket_add_quantity[data-max]', 'data-max');

        if (! is_string($quantity) || trim($quantity) === '') {
            return null;
        }

        return max(0, (int) $quantity);
    }

    private function normalizeAvailability(?string $availabilityUrl, string $availabilityLabel, ?int $stockQuantity): ?string
    {
        $availability = mb_strtolower((string) $availabilityUrl.' '.$availabilityLabel);

        if (str_contains($availability, 'instock') || str_contains($availability, 'dostępny') || ($stockQuantity !== null && $stockQuantity > 0)) {
            return 'in_stock';
        }

        if (str_contains($availability, 'outofstock') || str_contains($availability, 'niedostępny') || $stockQuantity === 0) {
            return 'out_of_stock';
        }

        return null;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function extractImages(Crawler $crawler, string $baseUrl): array
    {
        $images = [];

        foreach (['meta[property="og:image"][content]' => 'content', '#product-photo img[src]' => 'src', '#product-photo[data-gallery]' => 'data-gallery', '.gallery-item img[src]' => 'src', '.gallery-item[data-src]' => 'data-src'] as $selector => $attribute) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $attribute, $baseUrl): void {
                    $value = $node->attr($attribute);

                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    $url = $this->normalizeAssetUrl($value, $baseUrl);

                    if ($url === null) {
                        return;
                    }

                    $this->addImage($images, $url, $this->normalizeLabel((string) $node->attr('alt')) ?: null);
                });
            } catch (Throwable) {
                continue;
            }
        }

        return array_values($images);
    }

    private function isMedicalDevice(Crawler $crawler): bool
    {
        return str_contains(
            mb_strtolower($this->normalizeLabel($crawler->filter('.dsMedic')->first()->text(''))),
            'wyrób medyczny'
        );
    }

    /**
     * @return array<int, string>
     */
    private function warnings(string $name, ?float $priceGrossAmount, ?string $descriptionHtml): array
    {
        $warnings = [];

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($priceGrossAmount === null) {
            $warnings[] = 'Product gross price was not found.';
        }

        if ($descriptionHtml === null || trim(strip_tags($descriptionHtml)) === '') {
            $warnings[] = 'Product long description was not found.';
        }

        return $warnings;
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        return $this->firstAttr($crawler, 'meta[name="'.$name.'"][content]', 'content');
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        return $this->firstAttr($crawler, 'meta[property="'.$property.'"][content]', 'content');
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                return null;
            }

            $value = $node->attr($attribute);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function innerHtml(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                return null;
            }

            $html = '';

            foreach ($node->children() as $child) {
                $html .= $child->ownerDocument->saveHTML($child);
            }

            if ($html === '') {
                $html = $node->html('');
            }

            $html = $this->cleanProductHtml($html);

            return $html !== '' ? $html : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function plainTextFromHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = $this->normalizeLabel(strip_tags($html));

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, array<string, string|null>>  $images
     */
    private function addImage(array &$images, string $url, ?string $alt): void
    {
        if (! isset($images[$url])) {
            $images[$url] = [
                'url' => $url,
                'alt' => $alt,
            ];

            return;
        }

        if (($images[$url]['alt'] ?? null) === null && $alt !== null) {
            $images[$url]['alt'] = $alt;
        }
    }

    private function cleanProductHtml(string $html): string
    {
        $html = str_replace(['<!--[mode:html]-->', '<!--[mode:tiny]-->'], '', $html);
        $html = preg_replace('/\sdata-(?:start|end)=(["\'])[^"\']*\1/iu', '', $html) ?: $html;
        $html = preg_replace('/\scontenteditable=(["\'])[^"\']*\1/iu', '', $html) ?: $html;
        $html = preg_replace('/\sclass=(["\'])Mso[^"\']*\1/iu', '', $html) ?: $html;
        $html = preg_replace('/<\/?span\b[^>]*>/iu', '', $html) ?: $html;
        $html = preg_replace('/\s+/u', ' ', $html) ?: $html;
        $html = preg_replace('/>\s+</u', '><', $html) ?: $html;

        return trim($html);
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isPerukaUrl($url)) {
            return null;
        }

        $parts = parse_url($url);
        $path = isset($parts['path']) ? $this->normalizePath((string) $parts['path']) : '';

        if ($path === '/stThumbnailPlugin.php') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $sourceImage = $query['i'] ?? null;

            if (is_string($sourceImage) && trim($sourceImage) !== '') {
                return $this->normalizeAssetUrl($sourceImage, $url);
            }

            return null;
        }

        if (preg_match('#^/media/products/([^/]+)/images/thumbnail/(?:big_|large_|gallery_)([^/?]+)$#iu', $path, $matches) === 1) {
            return 'https://'.self::PERUKA_HOST.'/media/products/'.$matches[1].'/images/'.$matches[2];
        }

        if (preg_match('#^/media/products/[^/]+/images/[^/?]+$#iu', $path) === 1) {
            return 'https://'.self::PERUKA_HOST.$path;
        }

        return $url;
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = str_replace(['\\/', '\/'], '/', $url);

        if ($url === ''
            || str_starts_with($url, '#')
            || str_starts_with(mb_strtolower($url), 'mailto:')
            || str_starts_with(mb_strtolower($url), 'tel:')
            || str_starts_with(mb_strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::PERUKA_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $base = parse_url($baseUrl);

            if (! isset($base['scheme'], $base['host'])) {
                return null;
            }

            $basePath = $base['path'] ?? '/';
            $directory = str_ends_with($basePath, '/')
                ? rtrim($basePath, '/')
                : rtrim(dirname($basePath), '/');

            $url = $base['scheme'].'://'.$base['host'].$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.$host.$this->normalizePath($path).$query;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function slugFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = basename($path);
        $slug = preg_replace('/\.html$/', '', $slug) ?: $slug;

        return $slug !== '' ? $slug : null;
    }

    private function isPerukaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::PERUKA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            'peruka.pl', self::PERUKA_HOST => self::PERUKA_HOST,
            default => null,
        };
    }
}
