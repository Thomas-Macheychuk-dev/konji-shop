<?php

declare(strict_types=1);

namespace App\Services\Antar;

use Closure;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class AntarProductScraper
{
    private const ANTAR_HOST = 'antar.net';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 500;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 1000;

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

    public function withRetry(int $attempts, int $delayMilliseconds): self
    {
        $this->attempts = max(1, $attempts);
        $this->retryDelayMilliseconds = max(0, $delayMilliseconds);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $productLinkContext = null): array
    {
        $failed = [];
        $warnings = [];
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;

        $this->emit('Fetching Antar product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch Antar product page.';

            return $this->emptyResult($sourceUrl, $productLinkContext, $failed, $warnings);
        }

        return $this->extract($html, $sourceUrl, $productLinkContext, $failed, $warnings);
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @param  array<string, string>  $failed
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    public function extract(
        string $html,
        string $url,
        ?array $productLinkContext = null,
        array $failed = [],
        array $warnings = [],
    ): array {
        $crawler = new Crawler($html, $url);
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;
        $canonicalUrl = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href')
            ?? $this->firstMetaPropertyContent($crawler, 'og:url');
        $canonicalUrl = is_string($canonicalUrl) ? ($this->normalizeProductUrl($canonicalUrl, $sourceUrl) ?? $canonicalUrl) : null;

        $seoTitle = $this->normalizeText($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $name = $this->extractName($crawler, $seoTitle);
        $shortDescription = $this->extractShortDescription($crawler, $seoDescription);
        $descriptionHtml = $this->extractDescriptionHtml($crawler);
        $descriptionText = $this->plainTextFromHtml($descriptionHtml) ?? '';
        $categories = $this->extractCategories($crawler, $name);
        $attributes = $this->extractAttributes($crawler, $html);
        $documents = $this->extractDocuments($crawler, $sourceUrl);
        $images = $this->extractImages($crawler, $sourceUrl, $name);
        $sku = $this->extractSku($crawler, $html, $attributes);
        $brand = $this->extractBrand($html, $attributes);
        $ean = $this->extractEan($html, $attributes);
        $externalProductId = $this->externalProductId($productLinkContext, $canonicalUrl ?? $sourceUrl);
        $categoryFromContext = $this->stringValue($productLinkContext['source_category_name'] ?? null)
            ?? $this->stringValue($productLinkContext['category_name'] ?? null);

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($descriptionHtml === null || trim(strip_tags($descriptionHtml)) === '') {
            $warnings[] = 'Product description was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        if ($sku === null) {
            $warnings[] = 'Product catalogue number was not found.';
        }

        return $this->withProductLinkContext([
            'source' => 'antar',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $externalProductId,
            'slug' => $this->slugFromUrl($canonicalUrl ?? $sourceUrl),
            'name' => $name,
            'brand' => $brand,
            'category' => $categoryFromContext ?? ($categories === [] ? null : $categories[array_key_last($categories)]),
            'categories' => $categories,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'short_description' => $shortDescription,
            'description' => $descriptionText,
            'description_html' => $descriptionHtml,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'shipping_time' => null,
            'stock_quantity' => null,
            'sku' => $sku,
            'ean' => $ean,
            'attributes' => $attributes,
            'images' => $images,
            'documents' => $documents,
            'tabs' => $this->extractTabs($crawler),
            'variant_candidates' => [],
            'is_medical_device' => $this->isMedicalDevice($html),
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ], $productLinkContext);
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl, keepQuery: false);

        if ($url === null || ! $this->isAntarUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if (! str_starts_with($path, '/produkt/')) {
            return null;
        }

        return 'https://'.self::ANTAR_HOST.$path;
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        $lastFailure = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout($this->timeoutSeconds)
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->attempts) {
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            if ($response->successful()) {
                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if (! $this->shouldRetryStatus($response->status()) || $attempt >= $this->attempts) {
                break;
            }

            $this->pauseBeforeRetry();
        }

        $failed[$url] = $lastFailure ?? 'Unable to fetch Antar product page.';

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @param  array<string, string>  $failed
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function emptyResult(string $sourceUrl, ?array $productLinkContext, array $failed, array $warnings): array
    {
        return $this->withProductLinkContext([
            'source' => 'antar',
            'source_url' => $sourceUrl,
            'canonical_url' => null,
            'external_product_id' => $this->externalProductId($productLinkContext, $sourceUrl),
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => '',
            'brand' => null,
            'category' => $this->stringValue($productLinkContext['source_category_name'] ?? null),
            'categories' => [],
            'seo_title' => null,
            'seo_description' => null,
            'short_description' => null,
            'description' => '',
            'description_html' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'shipping_time' => null,
            'stock_quantity' => null,
            'sku' => null,
            'ean' => null,
            'attributes' => [],
            'images' => [],
            'documents' => [],
            'tabs' => [],
            'variant_candidates' => [],
            'is_medical_device' => false,
            'warnings' => $warnings,
            'failed_urls' => $failed,
        ], $productLinkContext);
    }

    private function extractName(Crawler $crawler, string $seoTitle): string
    {
        foreach (['h1.product_title', '.product_title', 'h1.entry-title', 'main h1', 'h1'] as $selector) {
            $name = $this->firstText($crawler, $selector);

            if ($name !== null) {
                return $name;
            }
        }

        $fallback = preg_replace('/\s+-\s+Antar\s*$/iu', '', $seoTitle) ?? $seoTitle;

        return $this->normalizeText($fallback);
    }

    private function extractShortDescription(Crawler $crawler, ?string $seoDescription): ?string
    {
        foreach ([
            '.woocommerce-product-details__short-description',
            '.summary .woocommerce-product-details__short-description',
            '.elementor-widget-woocommerce-product-short-description',
            '.product .summary > p',
        ] as $selector) {
            $text = $this->firstText($crawler, $selector);

            if ($text !== null) {
                return $text;
            }
        }

        return $seoDescription !== null && trim($seoDescription) !== '' ? $this->normalizeText($seoDescription) : null;
    }

    private function extractDescriptionHtml(Crawler $crawler): ?string
    {
        foreach ([
            '#tab-description',
            '.woocommerce-Tabs-panel--description',
            '[aria-labelledby="tab-title-description"]',
            '.woocommerce-tabs .woocommerce-Tabs-panel',
            '.entry-content .elementor-widget-theme-post-content',
            '.entry-content',
        ] as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $html = $this->innerHtml($crawler->filter($selector)->first());

            if ($html !== null && trim(strip_tags($html)) !== '') {
                return $this->cleanHtml($html);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractCategories(Crawler $crawler, string $productName): array
    {
        $categories = [];

        foreach ([
            '.posted_in a',
            '.product_meta a[href*="/produkty/"]',
            '.woocommerce-breadcrumb a[href*="/produkty/"]',
            'nav.woocommerce-breadcrumb a[href*="/produkty/"]',
        ] as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$categories, $productName): void {
                $text = $this->normalizeText($node->text('', false));

                if ($text === '' || mb_strtolower($text) === 'strona główna' || $text === $productName) {
                    return;
                }

                $categories[$text] = true;
            });
        }

        return array_keys($categories);
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string|null}>
     */
    private function extractAttributes(Crawler $crawler, string $html): array
    {
        $attributes = [];

        $this->addAttribute($attributes, 'catalogue_number', 'Numer katalogowy', $this->catalogueNumberFromText($this->pageText($crawler)));
        $this->addAttribute($attributes, 'producer', 'Producent', $this->valueAfterLabel($html, 'Producent'));
        $this->addAttribute($attributes, 'authorised_representative', 'Upoważniony przedstawiciel', $this->valueAfterLabel($html, 'Upoważniony przedstawiciel'));
        $this->addAttribute($attributes, 'advertising_entity', 'Podmiot prowadzący reklamę', $this->valueAfterLabel($html, 'Podmiot prowadzący reklamę'));

        $crawler->filter('.woocommerce-product-attributes tr, table tr')->each(function (Crawler $row) use (&$attributes): void {
            $cells = $row->filter('th, td');

            if ($cells->count() < 2) {
                return;
            }

            $label = $this->normalizeText($cells->eq(0)->text('', false));
            $valueParts = [];

            for ($index = 1; $index < $cells->count(); $index++) {
                $value = $this->normalizeText($cells->eq($index)->text('', false));

                if ($value !== '') {
                    $valueParts[] = $value;
                }
            }

            $this->addAttribute($attributes, Str::slug($label, '_'), $label, implode(' | ', $valueParts));
        });

        return array_values($attributes);
    }

    /**
     * @param  array<string, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function addAttribute(array &$attributes, string $code, string $label, ?string $value): void
    {
        $label = $this->normalizeText($label);
        $value = $value === null ? null : $this->normalizeText($value);

        if ($label === '' || $value === null || $value === '') {
            return;
        }

        $key = $code !== '' ? $code : Str::slug($label, '_');
        $attributes[$key] = [
            'code' => $key,
            'label' => $label,
            'value' => $value,
            'slug' => Str::slug($value) ?: null,
        ];
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractSku(Crawler $crawler, string $html, array $attributes): ?string
    {
        $sku = $this->firstText($crawler, '.sku')
            ?? $this->catalogueNumberFromText($this->pageText($crawler));

        if ($sku !== null) {
            return $sku;
        }

        foreach ($attributes as $attribute) {
            if (($attribute['code'] ?? '') === 'catalogue_number') {
                return $attribute['value'];
            }
        }

        return $this->catalogueNumberFromText($this->plainTextFromHtml($html) ?? '');
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractBrand(string $html, array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'] ?? '', ['producer', 'manufacturer', 'brand'], true)) {
                return $attribute['value'];
            }
        }

        return $this->valueAfterLabel($html, 'Producent');
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractEan(string $html, array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            $label = mb_strtolower($attribute['label'] ?? '');

            if (str_contains($label, 'ean') || str_contains($label, 'gtin')) {
                return preg_replace('/\D+/', '', $attribute['value']) ?: null;
            }
        }

        if (preg_match('/\b(?:EAN|GTIN)\s*:?\s*([0-9]{8,14})\b/iu', $this->plainTextFromHtml($html) ?? '', $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function externalProductId(?array $productLinkContext, string $url): string
    {
        $sourceExternalId = $this->stringValue($productLinkContext['external_id'] ?? null);

        if ($sourceExternalId !== null) {
            return $sourceExternalId;
        }

        return $this->externalIdFromUrl($url);
    }

    /**
     * @return array<int, array{url: string, alt: string|null, position: int}>
     */
    private function extractImages(Crawler $crawler, string $baseUrl, string $name): array
    {
        $images = [];

        $crawler->filter('.woocommerce-product-gallery__image')->each(function (Crawler $node) use (&$images, $baseUrl, $name): void {
            $link = $node->filter('a[href]')->first();
            $img = $node->filter('img')->first();
            $url = null;

            if ($link->count() > 0) {
                $url = $this->normalizeUrl((string) $link->attr('href'), $baseUrl, keepQuery: false);
            }

            if ($url === null && $img->count() > 0) {
                $url = $this->firstImageUrlFromNode($img, $baseUrl);
            }

            $alt = $img->count() > 0
                ? $this->normalizeText((string) ($img->attr('alt') ?? $img->attr('title') ?? $name))
                : $this->normalizeText((string) ($link->attr('title') ?? $name));

            $this->addImage($images, $url, $alt !== '' ? $alt : $name);
        });

        if ($images === []) {
            foreach (['.wp-post-image', '.product img'] as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $baseUrl, $name): void {
                    $url = $this->firstImageUrlFromNode($node, $baseUrl);
                    $alt = $this->normalizeText((string) ($node->attr('alt') ?? $node->attr('title') ?? $name));

                    $this->addImage($images, $url, $alt !== '' ? $alt : $name);
                });
            }
        }

        if ($images === []) {
            $crawler->filter('meta[property="og:image"][content]')->each(function (Crawler $node) use (&$images, $baseUrl, $name): void {
                $url = $this->firstImageUrlFromNode($node, $baseUrl);

                $this->addImage($images, $url, $name);
            });
        }

        return array_values($images);
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, position: int}>  $images
     */
    private function addImage(array &$images, ?string $url, string $alt): void
    {
        if ($url === null) {
            return;
        }

        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        if (str_contains($path, 'logo') || str_contains($path, 'placeholder')) {
            return;
        }

        $canonicalImageUrl = $this->canonicalImageUrl($url);

        if (isset($images[$canonicalImageUrl])) {
            return;
        }

        $images[$canonicalImageUrl] = [
            'url' => $canonicalImageUrl,
            'alt' => $alt !== '' ? $alt : null,
            'position' => count($images) + 1,
        ];
    }

    private function canonicalImageUrl(string $url): string
    {
        $url = preg_replace('#(\.(?:jpe?g|png|webp|gif|avif|svg))/+$#iu', '$1', $url) ?? $url;

        $url = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/iu', '$1', $url) ?? $url;

        return $url;
    }

    private function firstImageUrlFromNode(Crawler $node, string $baseUrl): ?string
    {
        foreach (['href', 'data-large_image', 'data-src', 'data-lazy-src', 'src', 'content'] as $attribute) {
            $value = $node->attr($attribute);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $url = $this->normalizeUrl($value, $baseUrl, keepQuery: false);

            if ($url !== null) {
                return $url;
            }
        }

        $srcset = $node->attr('srcset');

        if (is_string($srcset) && trim($srcset) !== '') {
            $candidates = array_reverse(array_map('trim', explode(',', $srcset)));

            foreach ($candidates as $candidate) {
                $parts = preg_split('/\s+/', $candidate);
                $url = isset($parts[0]) ? $this->normalizeUrl($parts[0], $baseUrl, keepQuery: false) : null;

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array{url: string, label: string|null, extension: string|null}>
     */
    private function extractDocuments(Crawler $crawler, string $baseUrl): array
    {
        $documents = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$documents, $baseUrl): void {
            $href = (string) $node->attr('href');
            $url = $this->normalizeUrl($href, $baseUrl, keepQuery: false);

            if ($url === null) {
                return;
            }

            $url = $this->canonicalDocumentUrl($url);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (! in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
                return;
            }

            $label = $this->normalizeText($node->text('', false));
            $documents[$url] = [
                'url' => $url,
                'label' => $label !== '' ? $label : null,
                'extension' => $extension,
            ];
        });

        return array_values($documents);
    }

    private function canonicalDocumentUrl(string $url): string
    {
        return preg_replace('#(\.(?:pdf|docx?|xlsx?))/+$#iu', '$1', $url) ?? $url;
    }

    /**
     * @return array<int, array{title: string, content: string, content_html: string|null}>
     */
    private function extractTabs(Crawler $crawler): array
    {
        $tabs = [];

        foreach ([
            '#tab-description' => 'Opis',
            '#tab-additional_information' => 'Informacje dodatkowe',
            '.woocommerce-Tabs-panel' => null,
            '.elementor-tab-content' => null,
        ] as $selector => $defaultTitle) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $crawler->filter($selector)->each(function (Crawler $node) use (&$tabs, $defaultTitle): void {
                $html = $this->innerHtml($node);
                $content = $this->plainTextFromHtml($html) ?? '';

                if ($content === '') {
                    return;
                }

                $id = $node->attr('id');
                $title = $defaultTitle ?? $this->tabTitleFromId(is_string($id) ? $id : null) ?? 'Sekcja produktu';
                $key = mb_strtolower($title.'|'.$content);

                $tabs[$key] = [
                    'title' => $title,
                    'content' => $content,
                    'content_html' => $html !== null ? $this->cleanHtml($html) : null,
                ];
            });
        }

        return array_values($tabs);
    }

    private function tabTitleFromId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $id = preg_replace('/^(tab-|elementor-tab-content-)/', '', $id) ?? $id;

        return Str::headline(str_replace(['_', '-'], ' ', $id));
    }

    private function isMedicalDevice(string $html): bool
    {
        $text = mb_strtolower($this->plainTextFromHtml($html) ?? '');

        return str_contains($text, 'wyrób medyczny')
            || str_contains($text, 'wyrobem medycznym')
            || str_contains($text, 'wyrobu medycznego');
    }

    private function catalogueNumberFromText(string $text): ?string
    {
        if (preg_match('/Numer\s+katalogowy\s*:\s*([\pL\pN._\/-]+)/iu', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function valueAfterLabel(string $html, string $label): ?string
    {
        $text = $this->plainTextFromHtml($html) ?? '';
        $quotedLabel = preg_quote($label, '/');

        if (preg_match('/'.$quotedLabel.'\s*:\s*(.+?)(?:\n|$)/iu', $text, $matches) !== 1) {
            return null;
        }

        return $this->normalizeText($matches[1]);
    }

    private function pageText(Crawler $crawler): string
    {
        return $this->normalizeText($crawler->filter('body')->first()->text('', false));
    }

    private function firstText(Crawler $crawler, string $selector): ?string
    {
        if ($crawler->filter($selector)->count() === 0) {
            return null;
        }

        $text = $this->normalizeText($crawler->filter($selector)->first()->text('', false));

        return $text !== '' ? $text : null;
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        if ($crawler->filter($selector)->count() === 0) {
            return null;
        }

        $value = $crawler->filter($selector)->first()->attr($attribute);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        return $this->firstAttr($crawler, 'meta[name="'.$name.'"][content]', 'content');
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        return $this->firstAttr($crawler, 'meta[property="'.$property.'"][content]', 'content');
    }

    private function innerHtml(Crawler $crawler): ?string
    {
        /** @var DOMElement|null $node */
        $node = $crawler->getNode(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $html = '';

        foreach ($node->childNodes as $childNode) {
            $html .= $node->ownerDocument?->saveHTML($childNode) ?: '';
        }

        return $html;
    }

    private function cleanHtml(string $html): string
    {
        libxml_use_internal_errors(true);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        foreach (iterator_to_array($document->getElementsByTagName('script')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($document->getElementsByTagName('style')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = '';
        $root = $document->documentElement;

        if ($root instanceof DOMElement) {
            foreach ($root->childNodes as $childNode) {
                $body .= $document->saveHTML($childNode);
            }
        }

        $body = preg_replace('/\s+/', ' ', $body) ?? $body;
        $body = preg_replace('/>\s+</', '><', $body) ?? $body;

        return trim($body);
    }

    private function plainTextFromHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</tr>', '<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s*\n+/', "\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", "\u{00a0}"], ' ', $text);
        $text = preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null, bool $keepQuery = false): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || $url === '#' || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        $baseUrl ??= 'https://'.self::ANTAR_HOST.'/';

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $parts = parse_url($baseUrl);
            $url = 'https://'.(string) ($parts['host'] ?? self::ANTAR_HOST).$url;
        } elseif (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $directory = rtrim(str_ends_with($basePath, '/') ? $basePath : dirname($basePath), '/');
            $url = 'https://'.(string) ($baseParts['host'] ?? self::ANTAR_HOST).$directory.'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = $keepQuery && isset($parts['query']) ? '?'.(string) $parts['query'] : '';

        return 'https://'.$host.$path.$query;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host === self::ANTAR_HOST ? self::ANTAR_HOST : null;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.trim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return $path === '/' ? '/' : $path.'/';
    }

    private function isAntarUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::ANTAR_HOST;
    }

    private function externalIdFromUrl(string $url): string
    {
        return $this->slugFromUrl($url);
    }

    private function slugFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        if ($segments !== []) {
            return (string) end($segments);
        }

        return Str::slug($url);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = $this->normalizeText($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function withProductLinkContext(array $product, ?array $context): array
    {
        if ($context === null) {
            return $product;
        }

        foreach ([
            'source_product_url' => 'url',
            'source_product_external_id' => 'external_id',
            'source_product_slug' => 'slug',
            'source_category_name' => 'source_category_name',
            'source_category_url' => 'source_category_url',
            'source_top_category_name' => 'source_top_category_name',
            'source_top_category_url' => 'source_top_category_url',
            'source_category_path' => 'source_category_path',
            'source_category_contexts' => 'source_category_contexts',
        ] as $targetKey => $sourceKey) {
            if (array_key_exists($sourceKey, $context)) {
                $product[$targetKey] = $context[$sourceKey];
            }
        }

        return $product;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl,en;q=0.8',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopAntarProductScraper/1.0; +https://konjishop.pl)',
        ];
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->requestDelayMilliseconds * 1000);
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->retryDelayMilliseconds * 1000);
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($message);
    }
}
