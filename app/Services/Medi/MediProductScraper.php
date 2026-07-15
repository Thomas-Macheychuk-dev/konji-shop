<?php

declare(strict_types=1);

namespace App\Services\Medi;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MediProductScraper
{
    private const MEDI_HOST = 'www.medi-polska.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 500;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 1500;

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

        $this->emit('Fetching medi product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch medi product page.';

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
        $canonicalUrl = is_string($canonicalUrl)
            ? ($this->normalizeProductUrl($canonicalUrl, $sourceUrl) ?? $canonicalUrl)
            : null;

        $structuredProduct = $this->structuredProduct($crawler);
        $config = $this->mainConfigurableProductConfig($crawler);
        $seoTitle = $this->normalizeText($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $name = $this->extractName($crawler, $structuredProduct, $seoTitle);
        $subtitle = $this->normalizeText($crawler->filter('.product-info-main h1.product-title .subtitle')->first()->text(''));
        $descriptionHtml = $this->extractDescriptionHtml($crawler);
        $description = $this->plainTextFromHtml($descriptionHtml)
            ?? $this->stringValue($structuredProduct['description'] ?? null)
            ?? '';
        $shortDescription = $subtitle !== ''
            ? $subtitle
            : ($this->firstMetaPropertyContent($crawler, 'og:description') ?? $seoDescription);
        $externalProductId = $this->extractExternalProductId($crawler, $config, $productLinkContext, $canonicalUrl ?? $sourceUrl);
        $sku = $this->extractSku($crawler, $structuredProduct);
        $priceGrossAmount = $this->extractPriceGrossAmount($crawler, $config);
        $currency = $this->firstMetaPropertyContent($crawler, 'product:price:currency') ?? 'PLN';
        $variantCandidates = $this->variantCandidates($config, $currency);
        $availabilityLabel = $this->extractAvailabilityLabel($crawler);
        $availability = $this->normalizeAvailability($availabilityLabel, $html, $variantCandidates);
        $shippingTime = $this->extractShippingTime($crawler, $availabilityLabel);
        $images = $this->extractImages($crawler, $config, $name);
        $categories = $this->extractCategories($crawler, $name);
        $categoryFromContext = $this->categoryNameFromContext($productLinkContext);
        $attributes = $this->extractAttributes($config, $sku);
        $tabs = $this->extractTabs($crawler);
        $brandName = $this->stringValue($structuredProduct['brand']['name'] ?? null) ?? 'medi';

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($description === '') {
            $warnings[] = 'Product description was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        if ($sku === null) {
            $warnings[] = 'Parent product SKU was not found.';
        }

        if ($priceGrossAmount === null) {
            $warnings[] = 'Product gross price was not found.';
        }

        if ($this->isConfigurableProduct($crawler) && $variantCandidates === []) {
            $warnings[] = 'Magento configurable product variants were not found.';
        }

        return $this->withProductLinkContext([
            'source' => 'medi',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $externalProductId,
            'slug' => $this->slugFromUrl($canonicalUrl ?? $sourceUrl),
            'name' => $name,
            'subtitle' => $subtitle !== '' ? $subtitle : null,
            'brand' => [
                'name' => $brandName,
                'slug' => Str::slug($brandName),
            ],
            'category' => $categoryFromContext ?? ($categories === [] ? null : $categories[array_key_last($categories)]),
            'categories' => $categories,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'short_description' => $shortDescription,
            'description' => $description,
            'description_html' => $descriptionHtml,
            'price_gross_amount' => $priceGrossAmount,
            'currency' => $currency,
            'availability' => $availability,
            'availability_label' => $availabilityLabel,
            'shipping_time' => $shippingTime,
            'stock_quantity' => $this->aggregateStockQuantity($variantCandidates),
            'sku' => $sku,
            'ean' => null,
            'attributes' => $attributes,
            'images' => $images,
            'tabs' => $tabs,
            'variant_candidates' => $variantCandidates,
            'variant_count' => count($variantCandidates),
            'available_variant_count' => count(array_filter(
                $variantCandidates,
                static fn (array $candidate): bool => ($candidate['availability'] ?? null) === 'in_stock',
            )),
            'is_medical_device' => $this->isMedicalDevice($description.' '.$this->plainTextFromHtml($descriptionHtml)),
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ], $productLinkContext);
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl);

        if ($normalized === null || mb_strtolower((string) parse_url($normalized, PHP_URL_HOST)) !== self::MEDI_HOST) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($normalized, PHP_URL_PATH));
        $pathLower = mb_strtolower($path);

        if (! str_starts_with($pathLower, '/shop/') || ! str_ends_with($pathLower, '.html')) {
            return null;
        }

        foreach ([
            '/shop/kategoria-produktu/',
            '/shop/czesci-ciala/',
            '/shop/wskazania-i-terapia/',
            '/shop/cialo/',
            '/shop/zdrowe-zycie/',
        ] as $blockedPrefix) {
            if (str_starts_with($pathLower, $blockedPrefix)) {
                return null;
            }
        }

        return 'https://'.self::MEDI_HOST.$path;
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
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
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

            if (! in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true) || $attempt >= $this->attempts) {
                break;
            }

            $this->pauseBeforeRetry();
        }

        $failed[$url] = $lastFailure ?? 'Unable to fetch medi product page.';

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
            'source' => 'medi',
            'source_url' => $sourceUrl,
            'canonical_url' => null,
            'external_product_id' => $this->externalProductIdFromUrl($sourceUrl),
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => '',
            'subtitle' => null,
            'brand' => ['name' => 'medi', 'slug' => 'medi'],
            'category' => $this->categoryNameFromContext($productLinkContext),
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
            'tabs' => [],
            'variant_candidates' => [],
            'variant_count' => 0,
            'available_variant_count' => 0,
            'is_medical_device' => false,
            'warnings' => $warnings,
            'failed_urls' => $failed,
        ], $productLinkContext);
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredProduct(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $contents = trim((string) $script->textContent);

            if ($contents === '') {
                continue;
            }

            try {
                $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $product = $this->findStructuredProduct($decoded);

            if ($product !== null) {
                return $product;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findStructuredProduct(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        if (($value['@type'] ?? null) === 'Product') {
            return $value;
        }

        if (is_array($value['mainEntity'] ?? null) && ($value['mainEntity']['@type'] ?? null) === 'Product') {
            return $value['mainEntity'];
        }

        foreach ($value as $child) {
            $product = $this->findStructuredProduct($child);

            if ($product !== null) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mainConfigurableProductConfig(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="text/x-magento-init"]') as $script) {
            $contents = trim((string) $script->textContent);

            if ($contents === '' || ! str_contains($contents, '"jsonConfig"') || ! str_contains($contents, 'data-role=swatch-options')) {
                continue;
            }

            try {
                $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $config = $decoded['[data-role=swatch-options]']['Magento_Swatches/js/swatch-renderer']['jsonConfig'] ?? null;

            if (is_array($config)) {
                return $config;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractName(Crawler $crawler, array $structuredProduct, string $seoTitle): string
    {
        foreach (['.product-info-main h1.product-title', 'h1.page-title span.base', 'h1.page-title'] as $selector) {
            $name = $this->normalizeText($crawler->filter($selector)->first()->text(''));
            $subtitle = $this->normalizeText($crawler->filter($selector.' .subtitle')->first()->text(''));

            if ($name !== '') {
                if ($subtitle !== '' && str_ends_with($name, $subtitle)) {
                    $name = trim(mb_substr($name, 0, mb_strlen($name) - mb_strlen($subtitle)));
                }

                return $name;
            }
        }

        $structuredName = $this->stringValue($structuredProduct['name'] ?? null);

        if ($structuredName !== null) {
            return $structuredName;
        }

        return trim(preg_replace('/\s*\|.*$/u', '', $seoTitle) ?? $seoTitle);
    }

    private function extractDescriptionHtml(Crawler $crawler): ?string
    {
        foreach (['#description .product.attribute.description .value', '#description .value', '.product.attribute.description .value'] as $selector) {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                continue;
            }

            $html = trim((string) $node->html(''));

            if ($html !== '') {
                return $html;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private function variantCandidates(array $config, string $currency): array
    {
        $index = is_array($config['index'] ?? null) ? $config['index'] : [];

        if ($index === []) {
            return [];
        }

        $attributes = is_array($config['attributes'] ?? null) ? $config['attributes'] : [];
        uasort($attributes, static fn (mixed $left, mixed $right): int => (int) ($left['position'] ?? 0) <=> (int) ($right['position'] ?? 0));

        $optionLookup = [];

        foreach ($attributes as $attributeId => $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            foreach (($attribute['options'] ?? []) as $option) {
                if (! is_array($option) || ! isset($option['id'])) {
                    continue;
                }

                $optionLookup[(string) $attributeId][(string) $option['id']] = $this->normalizeText((string) ($option['label'] ?? $option['id']));
            }
        }

        $salableProductIds = $this->salableProductIds($config['salable'] ?? null);
        $prices = is_array($config['optionPrices'] ?? null) ? $config['optionPrices'] : [];
        $skus = is_array($config['sku'] ?? null) ? $config['sku'] : [];
        $quantities = is_array($config['quantities'] ?? null) ? $config['quantities'] : [];
        $candidates = [];

        foreach ($index as $childProductId => $selectedOptions) {
            if (! is_array($selectedOptions)) {
                continue;
            }

            $candidateAttributes = [];
            $labelParts = [];

            foreach ($attributes as $attributeId => $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }

                $optionId = $this->stringValue($selectedOptions[(string) $attributeId] ?? null);

                if ($optionId === null) {
                    continue;
                }

                $label = $this->normalizeText((string) ($attribute['label'] ?? $attribute['code'] ?? $attributeId));
                $value = $optionLookup[(string) $attributeId][$optionId] ?? $optionId;

                $candidateAttributes[] = [
                    'code' => $this->stringValue($attribute['code'] ?? null),
                    'external_attribute_id' => (string) $attributeId,
                    'external_option_id' => $optionId,
                    'label' => $label,
                    'value' => $value,
                ];
                $labelParts[] = $label.': '.$value;
            }

            $priceData = is_array($prices[(string) $childProductId] ?? null) ? $prices[(string) $childProductId] : [];
            $quantity = is_numeric($quantities[(string) $childProductId] ?? null)
                ? (float) $quantities[(string) $childProductId]
                : null;
            $isSalable = $salableProductIds === [] || isset($salableProductIds[(string) $childProductId]);

            $candidates[] = [
                'external_variant_id' => (string) $childProductId,
                'sku' => $this->stringValue($skus[(string) $childProductId] ?? null),
                'label' => implode(' / ', $labelParts),
                'attributes' => $candidateAttributes,
                'price_gross_amount' => $this->numericValue($priceData['finalPrice']['amount'] ?? null),
                'old_price_gross_amount' => $this->numericValue($priceData['oldPrice']['amount'] ?? null),
                'currency' => $currency,
                'availability' => $isSalable ? 'in_stock' : 'out_of_stock',
                'stock_quantity' => $quantity === null ? null : ($quantity === floor($quantity) ? (int) $quantity : $quantity),
            ];
        }

        return $candidates;
    }

    /**
     * @return array<string, true>
     */
    private function salableProductIds(mixed $salable): array
    {
        if (! is_array($salable)) {
            return [];
        }

        $ids = [];

        foreach ($salable as $options) {
            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $productIds) {
                if (! is_array($productIds)) {
                    continue;
                }

                foreach ($productIds as $productId) {
                    if (is_scalar($productId)) {
                        $ids[(string) $productId] = true;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{url: string, alt: string}>
     */
    private function extractImages(Crawler $crawler, array $config, string $name): array
    {
        $images = [];

        foreach ($crawler->filter('.gallery-placeholder img.gallery-placeholder__image[src], meta[property="og:image"][content]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $node->getAttribute($node->tagName === 'meta' ? 'content' : 'src');
            $this->addImage($images, $url, $node->getAttribute('alt') ?: $name);
        }

        foreach (($config['images'] ?? []) as $variantImages) {
            if (! is_array($variantImages)) {
                continue;
            }

            foreach ($variantImages as $image) {
                if (! is_array($image)) {
                    continue;
                }

                $this->addImage(
                    $images,
                    (string) ($image['full'] ?? $image['img'] ?? ''),
                    $this->normalizeText((string) ($image['caption'] ?? $name)) ?: $name,
                );
            }
        }

        return array_values($images);
    }

    /**
     * @param  array<string, array{url: string, alt: string}>  $images
     */
    private function addImage(array &$images, string $url, string $alt): void
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
        $url = preg_replace('/&(?:wid|hei)=\d+/iu', '', $url) ?? $url;

        if ($url === '' || ! in_array(mb_strtolower((string) parse_url($url, PHP_URL_HOST)), ['s7e5a.scene7.com', self::MEDI_HOST], true)) {
            return;
        }

        $images[$url] = [
            'url' => $url,
            'alt' => $alt !== '' ? $alt : 'medi product image',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, string>>
     */
    private function extractAttributes(array $config, ?string $sku): array
    {
        $attributes = [];

        if ($sku !== null) {
            $attributes[] = [
                'code' => 'sku',
                'label' => 'SKU',
                'value' => $sku,
                'slug' => Str::slug($sku),
            ];
        }

        foreach (($config['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $values = [];

            foreach (($attribute['options'] ?? []) as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $value = $this->normalizeText((string) ($option['label'] ?? ''));

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            $label = $this->normalizeText((string) ($attribute['label'] ?? $attribute['code'] ?? ''));

            if ($label === '' || $values === []) {
                continue;
            }

            $attributes[] = [
                'code' => $this->stringValue($attribute['code'] ?? null) ?? Str::slug($label),
                'label' => $label,
                'value' => implode(' | ', array_values(array_unique($values))),
                'slug' => Str::slug(implode('-', $values)),
            ];
        }

        return $attributes;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function extractTabs(Crawler $crawler): array
    {
        $tabs = [];

        foreach ($crawler->filter('.product.info.detailed .data.item.content[id]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $code = trim($node->getAttribute('id'));
            $html = trim($this->innerHtml($node));
            $text = $this->normalizeText($node->textContent);

            if ($code === '' || ($html === '' && $text === '')) {
                continue;
            }

            $label = $this->normalizeText($crawler->filter('#tab-label-'.$code.'-title')->first()->text(''));

            $tabs[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : Str::headline($code),
                'html' => $html !== '' ? $html : null,
                'text' => $text !== '' ? $text : null,
            ];
        }

        return $tabs;
    }

    /**
     * @return list<string>
     */
    private function extractCategories(Crawler $crawler, string $productName): array
    {
        $categories = [];

        foreach ($crawler->filter('.breadcrumbs .items .item') as $node) {
            $label = $this->normalizeText($node->textContent);

            if ($label === '' || $label === $productName || in_array(mb_strtolower($label), ['home', 'strona główna'], true)) {
                continue;
            }

            $categories[] = $label;
        }

        return array_values(array_unique($categories));
    }

    /**
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractSku(Crawler $crawler, array $structuredProduct): ?string
    {
        return $this->firstAttr($crawler, '#product_addtocart_form[data-product-sku]', 'data-product-sku')
            ?? $this->stringValue($structuredProduct['sku'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function extractPriceGrossAmount(Crawler $crawler, array $config): ?float
    {
        $metaPrice = $this->firstMetaPropertyContent($crawler, 'product:price:amount');

        if ($metaPrice !== null && is_numeric($metaPrice)) {
            return (float) $metaPrice;
        }

        $price = $this->firstAttr($crawler, '.product-info-price [data-price-type="finalPrice"][data-price-amount]', 'data-price-amount');

        if ($price !== null && is_numeric($price)) {
            return (float) $price;
        }

        return $this->numericValue($config['prices']['finalPrice']['amount'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function extractExternalProductId(Crawler $crawler, array $config, ?array $productLinkContext, string $url): string
    {
        return $this->firstAttr($crawler, '#product_addtocart_form input[name="product"]', 'value')
            ?? $this->stringValue($config['productId'] ?? null)
            ?? $this->stringValue($productLinkContext['external_id'] ?? null)
            ?? $this->externalProductIdFromUrl($url);
    }

    private function extractAvailabilityLabel(Crawler $crawler): ?string
    {
        foreach (['.product-info-main .stock.available .text', '.product-info-main .stock.unavailable', '.product-info-main .stock'] as $selector) {
            $label = $this->normalizeText($crawler->filter($selector)->first()->text(''));

            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $variantCandidates
     */
    private function normalizeAvailability(?string $label, string $html, array $variantCandidates): string
    {
        if ($variantCandidates !== [] && array_filter(
            $variantCandidates,
            static fn (array $candidate): bool => ($candidate['availability'] ?? null) === 'in_stock',
        ) !== []) {
            return 'in_stock';
        }

        $haystack = mb_strtolower(($label ?? '').' '.$html);

        if (str_contains($haystack, 'stock unavailable') || str_contains($haystack, 'niedostępny') || str_contains($haystack, 'brak w magazynie')) {
            return 'out_of_stock';
        }

        if (str_contains($haystack, 'na zamówienie')) {
            return 'preorder';
        }

        if (str_contains($haystack, 'stock available') || str_contains($haystack, 'wysyłka w ciągu') || str_contains($haystack, 'instock')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    private function extractShippingTime(Crawler $crawler, ?string $availabilityLabel): ?string
    {
        $text = $availabilityLabel ?? $this->normalizeText($crawler->filter('.product-info-main')->first()->text(''));

        if (preg_match('/wysyłka\s+w\s+ciągu\s+([^.;]+)/iu', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $variantCandidates
     */
    private function aggregateStockQuantity(array $variantCandidates): int|float|null
    {
        $total = 0.0;
        $found = false;

        foreach ($variantCandidates as $candidate) {
            if (! is_numeric($candidate['stock_quantity'] ?? null)) {
                continue;
            }

            $total += (float) $candidate['stock_quantity'];
            $found = true;
        }

        if (! $found) {
            return null;
        }

        return $total === floor($total) ? (int) $total : $total;
    }

    private function isConfigurableProduct(Crawler $crawler): bool
    {
        $class = $this->firstAttr($crawler, 'body', 'class') ?? '';

        return str_contains($class, 'page-product-configurable');
    }

    private function isMedicalDevice(string $text): bool
    {
        $text = mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));

        foreach ([
            'to jest wyrób medyczny',
            'jest wyrobem medycznym',
            'wyrób medyczny',
            'wyroby medyczne',
            'produkt medyczny',
            'medyczny produkt kompresyjny',
            'medyczne pończochy kompresyjne',
            'produkt ortotyczny',
        ] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function withProductLinkContext(array $payload, ?array $context): array
    {
        if ($context === null) {
            return $payload;
        }

        $path = $this->stringList($context['source_category_path'] ?? $context['category_path'] ?? []);
        $categoryName = $this->stringValue($context['source_category_name'] ?? $context['category_name'] ?? null)
            ?? ($path === [] ? null : $path[array_key_last($path)]);
        $topCategoryName = $this->stringValue($context['source_top_category_name'] ?? $context['top_category_name'] ?? null)
            ?? ($path[0] ?? $categoryName);

        return array_merge($payload, [
            'category' => $categoryName ?? $payload['category'],
            'source_category_name' => $categoryName,
            'source_category_url' => $this->stringValue($context['source_category_url'] ?? $context['category_url'] ?? null),
            'source_top_category_name' => $topCategoryName,
            'source_top_category_url' => $this->stringValue($context['source_top_category_url'] ?? $context['top_category_url'] ?? null),
            'source_category_path' => $path,
            'source_category_contexts' => is_array($context['source_category_contexts'] ?? null)
                ? array_values($context['source_category_contexts'])
                : [],
            'source_product_list_name' => $this->stringValue($context['name'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function categoryNameFromContext(?array $context): ?string
    {
        if ($context === null) {
            return null;
        }

        $name = $this->stringValue($context['source_category_name'] ?? $context['category_name'] ?? null);

        if ($name !== null) {
            return $name;
        }

        $path = $this->stringList($context['source_category_path'] ?? $context['category_path'] ?? []);

        return $path === [] ? null : $path[array_key_last($path)];
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $baseHost = parse_url($baseUrl ?? 'https://'.self::MEDI_HOST, PHP_URL_HOST) ?: self::MEDI_HOST;
            $url = 'https://'.$baseHost.$url;
        } elseif (! preg_match('#^https?://#iu', $url) && $baseUrl !== null) {
            $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
            $baseDirectory = str_contains($basePath, '/') ? dirname($basePath) : '/';
            $url = 'https://'.(parse_url($baseUrl, PHP_URL_HOST) ?: self::MEDI_HOST).'/'.trim($baseDirectory.'/'.$url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
            return null;
        }

        return 'https://'.mb_strtolower((string) $parts['host']).$this->normalizePath((string) $parts['path']);
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('#/+#u', '/', '/'.ltrim($path, '/')) ?? $path;

        return $path !== '/' ? rtrim($path, '/') : $path;
    }

    private function externalProductIdFromUrl(string $url): string
    {
        return $this->slugFromUrl($url);
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = basename($path);

        return preg_replace('/\.html$/iu', '', $basename) ?? $basename;
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        $node = $crawler->filter($selector)->first();

        if ($node->count() === 0) {
            return null;
        }

        $value = $node->attr($attribute);

        return is_string($value) && trim($value) !== '' ? html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5) : null;
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        return $this->firstAttr($crawler, 'meta[name="'.$name.'"]', 'content');
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        return $this->firstAttr($crawler, 'meta[property="'.$property.'"]', 'content');
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function plainTextFromHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = $this->normalizeText(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $html)));

        return $text !== '' ? $text : null;
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            $item = $this->stringValue($item);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopMediScraper/1.0; +https://konji.pl)',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
