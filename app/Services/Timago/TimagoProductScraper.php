<?php

declare(strict_types=1);

namespace App\Services\Timago;

use Closure;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class TimagoProductScraper
{
    private const TIMAGO_HOST = 'www.timago.com';

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
     * @param  array<string, mixed>|null  $productLinkContext
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $productLinkContext = null): array
    {
        $failed = [];
        $warnings = [];
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;

        $this->emit('Fetching Timago product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch Timago product page.';

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
    public function extract(string $html, string $url, ?array $productLinkContext = null, array $failed = [], array $warnings = []): array
    {
        $crawler = new Crawler($html, $url);
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;
        $canonicalUrl = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href');
        $canonicalUrl = is_string($canonicalUrl) ? ($this->normalizeProductUrl($canonicalUrl, $sourceUrl) ?? $canonicalUrl) : null;

        $seoTitle = $this->normalizeLabel($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');

        $productHtml = $this->removeSiteChrome($html);
        $productCrawler = new Crawler($productHtml, $sourceUrl);

        $name = $this->extractName($productCrawler, $seoTitle);
        $descriptionHtml = $this->extractDescriptionHtml($productCrawler, $name);
        $shortDescription = $this->extractShortDescription($seoDescription, $descriptionHtml);
        $categories = $this->extractCategories($productCrawler, $name);
        $categoryFromContext = $this->categoryNameFromContext($productLinkContext);
        $attributes = $this->extractAttributes($productCrawler, $descriptionHtml);
        $brand = $this->extractBrand($crawler, $attributes);
        $sku = $this->extractSku($crawler, $productHtml, $attributes);
        $ean = $this->extractEan($crawler, $productHtml, $attributes);
        $priceGrossAmount = $this->extractPriceGrossAmount($productCrawler, $productHtml);
        $availabilityLabel = $this->extractAvailabilityLabel($productCrawler, $productHtml);
        $availability = $this->normalizeAvailability($availabilityLabel, $productHtml);
        $shippingTime = $this->extractShippingTime($productCrawler, $productHtml);
        $externalProductId = $this->extractExternalProductId($productCrawler, $productHtml, $canonicalUrl ?? $sourceUrl);
        $images = $this->extractImages($productCrawler, $sourceUrl, $name, $externalProductId);
        $variantCandidates = $this->extractVariantCandidates($productCrawler, $priceGrossAmount);

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($descriptionHtml === null || trim(strip_tags($descriptionHtml)) === '') {
            $warnings[] = 'Product description was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        return $this->withProductLinkContext([
            'source' => 'timago',
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
            'description' => $this->plainTextFromHtml($descriptionHtml) ?? '',
            'description_html' => $descriptionHtml,
            'price_gross_amount' => $priceGrossAmount,
            'currency' => 'PLN',
            'availability' => $availability,
            'availability_label' => $availabilityLabel,
            'shipping_time' => $shippingTime,
            'stock_quantity' => null,
            'sku' => $sku,
            'ean' => $ean,
            'attributes' => $attributes,
            'images' => $images,
            'tabs' => $this->tabs($descriptionHtml, $attributes),
            'variant_candidates' => $variantCandidates,
            'is_medical_device' => $this->isMedicalDevice($html, $categories, $attributes),
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ], $productLinkContext);
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isTimagoUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));
        $pathLower = mb_strtolower($path);

        if (! str_starts_with($pathLower, '/pl/') || ! str_ends_with($pathLower, '.html')) {
            return null;
        }

        return 'https://'.self::TIMAGO_HOST.$path;
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        $this->pauseBeforeRequest();

        try {
            $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->withHeaders($this->headers())
                ->get($url);
        } catch (Throwable $exception) {
            $failed[$url] = $exception->getMessage();

            return null;
        }

        if (! $response->successful()) {
            $failed[$url] = 'HTTP '.$response->status();

            return null;
        }

        return $response->body();
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
            'source' => 'timago',
            'source_url' => $sourceUrl,
            'canonical_url' => null,
            'external_product_id' => $this->productIdFromUrl($sourceUrl),
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => '',
            'brand' => null,
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
            'is_medical_device' => false,
            'warnings' => $warnings,
            'failed_urls' => $failed,
        ], $productLinkContext);
    }

    private function extractName(Crawler $crawler, string $seoTitle): string
    {
        foreach (['h1', '.product__title', '.product-title'] as $selector) {
            $name = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($name !== '') {
                return $name;
            }
        }

        $ogTitle = $this->firstMetaPropertyContent($crawler, 'og:title');

        if (is_string($ogTitle) && trim($ogTitle) !== '') {
            return $this->normalizeLabel($ogTitle);
        }

        return $this->normalizeLabel(preg_replace('/\s*[-|]\s*Timago.*$/iu', '', $seoTitle) ?: $seoTitle);
    }

    private function extractDescriptionHtml(Crawler $crawler, string $name): ?string
    {
        foreach ([
            '.product__txt',
            '.product__description',
            '.product-description',
            '.product__content',
            '.product-details',
            '#description',
            'main article',
        ] as $selector) {
            $html = $this->innerHtml($crawler, $selector);

            if ($html !== null && trim(strip_tags($html)) !== '') {
                return $this->cleanDescriptionHtml($html, $name);
            }
        }

        return null;
    }

    private function cleanDescriptionHtml(string $html, string $name): string
    {
        $html = preg_replace('#<(script|style|nav|header|footer)\b[^>]*>.*?</\1>#isu', '', $html) ?? $html;
        $html = preg_replace('#<form\b[^>]*>.*?</form>#isu', '', $html) ?? $html;
        $html = preg_replace('#<a\b[^>]*href=[\'\"][^\'\"]+[\'\"][^>]*>(.*?)</a>#isu', '$1', $html) ?? $html;
        $html = $this->rewriteRelativeImageAttributes($html);

        if ($name !== '') {
            $html = preg_replace('#^\s*<h1\b[^>]*>\s*'.preg_quote($name, '#').'\s*</h1>\s*#isu', '', $html) ?? $html;
        }

        return trim($html);
    }

    /**
     * @return array<int, string>
     */
    private function extractCategories(Crawler $crawler, string $name): array
    {
        $categories = [];

        foreach (['.breadcrumbs a', '.breadcrumb a', '.path a'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$categories, $name): void {
                    $label = $this->normalizeLabel($node->text(''));

                    if ($label === '' || $this->isNonCategoryBreadcrumbLabel($label, $name)) {
                        return;
                    }

                    $categories[$label] = true;
                });
            } catch (Throwable) {
                continue;
            }

            if ($categories !== []) {
                break;
            }
        }

        return array_keys($categories);
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string|null}>
     */
    private function extractAttributes(Crawler $crawler, ?string $descriptionHtml): array
    {
        $attributes = [];

        foreach (['table tr', '.product__params li', '.product-params li', '.parameters li'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $row) use (&$attributes): void {
                    $cells = $row->filter('th, td');

                    if ($cells->count() >= 2) {
                        $this->addAttribute($attributes, $cells->eq(0)->text(''), $cells->eq(1)->text(''));

                        return;
                    }

                    $text = $this->normalizeLabel($row->text(''));
                    $parts = preg_split('/\s*[:–-]\s*/u', $text, 2);

                    if (is_array($parts) && count($parts) === 2) {
                        $this->addAttribute($attributes, $parts[0], $parts[1]);
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        if ($descriptionHtml !== null) {
            $plain = $this->plainTextFromHtml($descriptionHtml) ?? '';

            foreach (['Kod produktu', 'Kod wyrobu', 'Numer katalogowy', 'Producent', 'Rozmiar', 'Wymiary', 'Kolor', 'Materiał'] as $label) {
                $value = $this->textAfterLabel($plain, $label.':');

                if ($value !== null) {
                    $this->addAttribute($attributes, $label, $value);
                }
            }
        }

        return array_values($attributes);
    }

    /**
     * @param  array<string, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function addAttribute(array &$attributes, string $label, string $value): void
    {
        $label = $this->normalizeAttributeLabel($label);
        $value = $this->normalizeLabel($value);

        if ($label === '' || $value === '' || ! $this->reasonableExtractedValue($value)) {
            return;
        }

        if (in_array(mb_strtolower($label), ['opis', 'galeria', 'pliki do pobrania'], true)) {
            return;
        }

        $code = $this->slugify($label);
        $key = $code.'|'.mb_strtolower($value);

        if (isset($attributes[$key])) {
            return;
        }

        $attributes[$key] = [
            'code' => $code,
            'label' => $label,
            'value' => $value,
            'slug' => $this->slugify($value),
        ];
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractBrand(Crawler $crawler, array $attributes): ?array
    {
        $brand = $this->firstAttr($crawler, 'meta[itemprop="brand"][content]', 'content');

        if (is_string($brand) && trim($brand) !== '') {
            return $this->brandPayload($brand);
        }

        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'], ['producent', 'manufacturer', 'brand'], true)) {
                return $this->brandPayload($attribute['value']);
            }
        }

        return $this->brandPayload('Timago');
    }

    /**
     * @return array{name: string, slug: string|null}
     */
    private function brandPayload(string $name): array
    {
        $name = $this->normalizeLabel($name);

        return [
            'name' => $name,
            'slug' => $this->slugify($name),
        ];
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractSku(Crawler $crawler, string $html, array $attributes): ?string
    {
        foreach (['meta[itemprop="sku"][content]', 'input[name="product_code"][value]', '[data-product-code]'] as $selector) {
            $attribute = str_contains($selector, 'content]') ? 'content' : (str_contains($selector, 'value]') ? 'value' : 'data-product-code');
            $sku = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($sku) && trim($sku) !== '') {
                return trim($sku);
            }
        }

        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'], ['kod-produktu', 'kod-wyrobu', 'numer-katalogowy', 'sku'], true)) {
                return $attribute['value'];
            }
        }

        $text = $this->normalizeLabel(strip_tags($html));

        return $this->textBetweenLabelAndBoundary($text, 'Kod produktu')
            ?? $this->textBetweenLabelAndBoundary($text, 'Kod wyrobu')
            ?? $this->textBetweenLabelAndBoundary($text, 'Numer katalogowy');
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractEan(Crawler $crawler, string $html, array $attributes): ?string
    {
        foreach (['meta[itemprop="gtin13"][content]', 'meta[itemprop="gtin"][content]'] as $selector) {
            $ean = $this->firstAttr($crawler, $selector, 'content');

            if (is_string($ean) && trim($ean) !== '') {
                return trim($ean);
            }
        }

        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'], ['ean', 'kod-ean', 'gtin'], true)) {
                return $attribute['value'];
            }
        }

        if (preg_match('/\b(?:EAN|GTIN|Kod EAN)\s*[:\-]\s*([0-9]{8,14})\b/iu', $html, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function extractPriceGrossAmount(Crawler $crawler, string $html): ?string
    {
        foreach (['meta[property="product:price:amount"][content]', '[itemprop="price"][content]', '[data-price]'] as $selector) {
            $attribute = str_contains($selector, 'content]') ? 'content' : 'data-price';
            $price = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($price) && trim($price) !== '') {
                return $this->normalizePrice($price);
            }
        }

        foreach (['.price', '.product__price', '.product-price'] as $selector) {
            $price = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($price !== '') {
                return $this->normalizePrice($price);
            }
        }

        if (preg_match('/([0-9]+(?:[\s.][0-9]{3})*(?:[,.][0-9]{1,2})?)\s*(?:zł|PLN)/iu', strip_tags($html), $match) === 1) {
            return $this->normalizePrice($match[1]);
        }

        return null;
    }

    private function extractAvailabilityLabel(Crawler $crawler, string $html): ?string
    {
        foreach (['.availability', '.product__availability', '.stock', '[itemprop="availability"]'] as $selector) {
            $label = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($label !== '') {
                return $label;
            }
        }

        $text = $this->normalizeLabel(strip_tags($html));

        foreach (['Dostępność:', 'Dostępny:', 'Termin realizacji:'] as $label) {
            $value = $this->textAfterLabel($text, $label);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeAvailability(?string $availabilityLabel, string $html): string
    {
        $text = mb_strtolower($availabilityLabel ?? strip_tags($html));

        if (str_contains($text, 'brak') || str_contains($text, 'niedostęp')) {
            return 'out_of_stock';
        }

        if (str_contains($text, 'dostęp') || str_contains($text, 'magazyn') || str_contains($text, '48 godzin')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    private function extractShippingTime(Crawler $crawler, string $html): ?string
    {
        foreach (['.delivery', '.shipping', '.product__delivery'] as $selector) {
            $label = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($label !== '') {
                return $label;
            }
        }

        return $this->textAfterLabel($this->normalizeLabel(strip_tags($html)), 'Wysyłka:')
            ?? $this->textAfterLabel($this->normalizeLabel(strip_tags($html)), 'Termin realizacji:');
    }

    /**
     * @return array<int, array{url: string, alt: string|null, sort_order: int}>
     */
    private function extractImages(Crawler $crawler, string $sourceUrl, string $name, ?string $externalProductId = null): array
    {
        $images = [];
        $allImages = [];

        foreach (['.product__gallery img', '.product-gallery img', '.gallery img', '.product img', 'article img', 'main img', 'body img', 'img'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $image) use (&$allImages, $sourceUrl, $name): void {
                    foreach (['data-src', 'data-original', 'srcset', 'src'] as $attribute) {
                        $value = $this->firstNodeAttr($image, $attribute);

                        if (! is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $url = $this->normalizeAssetUrl($this->firstSrcsetUrl($value), $sourceUrl);

                        if ($url === null || $this->isPlaceholderImageUrl($url) || isset($allImages[$url])) {
                            continue;
                        }

                        $allImages[$url] = [
                            'url' => $url,
                            'alt' => $this->normalizeLabel($this->firstNodeAttr($image, 'alt') ?? '') ?: $name,
                            'sort_order' => count($allImages),
                        ];

                        break;
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($allImages as $url => $image) {
            if ($externalProductId !== null && ! $this->assetUrlBelongsToProduct($url, $externalProductId)) {
                continue;
            }

            $images[$url] = [
                ...$image,
                'sort_order' => count($images),
            ];
        }

        if ($images !== []) {
            return array_values($images);
        }

        foreach ($allImages as $url => $image) {
            if ($this->isLikelyProductImageUrl($url)) {
                $images[$url] = [
                    ...$image,
                    'sort_order' => count($images),
                ];
            }
        }

        return array_values($images);
    }

    /**
     * @return array<int, array{name: string, sku: string|null, price_gross_amount: string|null, currency: string, availability: string, attributes: array<int, array{code: string, label: string, value: string, slug: string|null}>}>
     */
    private function extractVariantCandidates(Crawler $crawler, ?string $priceGrossAmount): array
    {
        $variants = [];

        foreach (['select[name*="size"]', 'select[name*="rozmiar"]', 'select'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $select) use (&$variants, $priceGrossAmount): void {
                    $label = $this->normalizeLabel($this->firstNodeAttr($select, 'name') ?? 'Wariant');

                    $select->filter('option')->each(function (Crawler $option) use (&$variants, $priceGrossAmount, $label): void {
                        $value = $this->normalizeLabel($option->text(''));

                        if ($value === '' || str_contains(mb_strtolower($value), 'wybierz')) {
                            return;
                        }

                        $key = mb_strtolower($label.'|'.$value);

                        if (isset($variants[$key])) {
                            return;
                        }

                        $variants[$key] = [
                            'name' => $value,
                            'sku' => $this->firstNodeAttr($option, 'data-code') ?: null,
                            'price_gross_amount' => $priceGrossAmount,
                            'currency' => 'PLN',
                            'availability' => 'unknown',
                            'attributes' => [[
                                'code' => $this->slugify($label) ?: 'wariant',
                                'label' => $label,
                                'value' => $value,
                                'slug' => $this->slugify($value),
                            ]],
                        ];
                    });
                });
            } catch (Throwable) {
                continue;
            }
        }

        return array_values($variants);
    }

    private function extractExternalProductId(Crawler $crawler, string $html, string $url): string
    {
        foreach (['input[name="product_id"][value]', '[data-product-id]', 'meta[itemprop="productID"][content]'] as $selector) {
            $attribute = str_contains($selector, 'content]') ? 'content' : (str_contains($selector, 'value]') ? 'value' : 'data-product-id');
            $id = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($id) && trim($id) !== '') {
                return trim($id);
            }
        }

        $urlProductId = $this->numericProductIdFromUrl($url);

        if ($urlProductId !== null) {
            return $urlProductId;
        }

        $imageProductId = $this->dominantProductImageId($html);

        if ($imageProductId !== null) {
            return $imageProductId;
        }

        return $this->productIdFromUrl($url) ?? $this->slugFromUrl($url) ?? sha1($url);
    }

    private function isMedicalDevice(string $html, array $categories, array $attributes): bool
    {
        $text = mb_strtolower($this->normalizeLabel(strip_tags($html)).' '.implode(' ', $categories));

        return str_contains($text, 'wyrób medyczny')
            || str_contains($text, 'wyrobami medycznymi')
            || str_contains($text, 'medyczn');
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @return array<string, mixed>
     */
    private function withProductLinkContext(array $product, ?array $productLinkContext): array
    {
        if ($productLinkContext === null) {
            return $product;
        }

        foreach (['category_name', 'category_url', 'top_category_name', 'top_category_url', 'category_path'] as $key) {
            if (array_key_exists($key, $productLinkContext)) {
                $product['product_link_'.$key] = $productLinkContext[$key];
            }
        }

        if (($product['category'] ?? null) === null && is_string($productLinkContext['category_name'] ?? null)) {
            $product['category'] = $productLinkContext['category_name'];
        }

        if (($product['categories'] ?? []) === [] && is_array($productLinkContext['category_path'] ?? null)) {
            $product['categories'] = array_values(array_filter($productLinkContext['category_path'], 'is_string'));
        }

        return $product;
    }

    private function categoryNameFromContext(?array $productLinkContext): ?string
    {
        if (! is_array($productLinkContext)) {
            return null;
        }

        return is_string($productLinkContext['category_name'] ?? null) && trim($productLinkContext['category_name']) !== ''
            ? trim($productLinkContext['category_name'])
            : null;
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     * @return array<int, array{title: string, content: string}>
     */
    private function tabs(?string $descriptionHtml, array $attributes): array
    {
        $tabs = [];

        if ($descriptionHtml !== null && trim(strip_tags($descriptionHtml)) !== '') {
            $tabs[] = [
                'title' => 'Opis',
                'content' => $descriptionHtml,
            ];
        }

        if ($attributes !== []) {
            $rows = '';

            foreach ($attributes as $attribute) {
                $rows .= '<tr><th>'.htmlspecialchars($attribute['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</th><td>'.htmlspecialchars($attribute['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td></tr>';
            }

            $tabs[] = [
                'title' => 'Dane produktu',
                'content' => '<table><tbody>'.$rows.'</tbody></table>',
            ];
        }

        return $tabs;
    }

    private function extractShortDescription(?string $seoDescription, ?string $descriptionHtml): ?string
    {
        if (is_string($seoDescription) && trim($seoDescription) !== '') {
            return $this->normalizeLabel($seoDescription);
        }

        $plain = $this->plainTextFromHtml($descriptionHtml) ?? '';

        if ($plain === '') {
            return null;
        }

        return mb_substr($plain, 0, 500);
    }

    private function removeSiteChrome(string $html): string
    {
        foreach ([
            '#<header\b[^>]*>.*?</header>#isu',
            '#<footer\b[^>]*>.*?</footer>#isu',
            '#<nav\b[^>]*>.*?</nav>#isu',
            '#<script\b[^>]*>.*?</script>#isu',
            '#<style\b[^>]*>.*?</style>#isu',
        ] as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        foreach ([
            'header',
            'footer',
            'nav',
            'header__',
            'nav__',
            'footer__',
            'partners',
        ] as $classFragment) {
            $html = preg_replace(
                '#<([a-z0-9]+)\b[^>]*class=["\'][^"\']*'.preg_quote($classFragment, '#').'[^"\']*["\'][^>]*>.*?</\1>#isu',
                '',
                $html,
            ) ?? $html;
        }

        return $html;
    }

    private function textBetweenLabelAndBoundary(string $text, string $label): ?string
    {
        $pattern = '/\b'.preg_quote($label, '/').'\s*:\s*(.+?)(?=\s+(?:producent|producer|kod ean|ean|gtin|sprawdź|sprawdz|zalety|opis|dane techniczne|do pobrania|produkty powiązane)\b|$)/iu';

        if (preg_match($pattern, $text, $match) !== 1) {
            return null;
        }

        $value = $this->cleanSkuCandidate($this->normalizeLabel($match[1]));

        return $value !== null && $this->reasonableExtractedValue($value) ? $value : null;
    }

    private function cleanSkuCandidate(string $value): ?string
    {
        $value = $this->normalizeLabel($value);

        foreach ([
            'Produkt wkrótce',
            'Produkt wkrotce',
            'Dostępność',
            'Dostepnosc',
            'Dostępny',
            'Niedostępny',
            'Opis',
            'Zalety',
            'Dane techniczne',
            'Do pobrania',
            'Produkty powiązane',
        ] as $boundary) {
            $position = mb_stripos($value, $boundary);

            if ($position !== false) {
                $value = trim(mb_substr($value, 0, $position));
            }
        }

        $value = trim($value);
        $value = trim($value, ':-–—');

        return $value !== '' ? $value : null;
    }

    private function reasonableExtractedValue(string $value): bool
    {
        return $value !== '' && mb_strlen($value) <= 120;
    }

    private function numericProductIdFromUrl(string $url): ?string
    {
        $slug = $this->slugFromUrl($url);

        if ($slug === null) {
            return null;
        }

        if (preg_match('/-(\d+)$/u', $slug, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function dominantProductImageId(string $html): ?string
    {
        if (preg_match_all('#/_pliki_/produkty/(\d+)/#u', $html, $matches) === false || $matches[1] === []) {
            return null;
        }

        $counts = [];

        foreach ($matches[1] as $id) {
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        arsort($counts);

        foreach ($counts as $id => $count) {
            return (string) $id;
        }

        return null;
    }

    private function assetUrlBelongsToProduct(string $url, string $externalProductId): bool
    {
        return str_contains((string) parse_url($url, PHP_URL_PATH), '/_pliki_/produkty/'.$externalProductId.'/');
    }

    private function isLikelyProductImageUrl(string $url): bool
    {
        return str_contains((string) parse_url($url, PHP_URL_PATH), '/_pliki_/produkty/');
    }

    private function rewriteRelativeImageAttributes(string $html): string
    {
        return preg_replace_callback(
            '#\b(src|srcset|data-src|data-original)=([\'\"])(.*?)\2#isu',
            function (array $match): string {
                $attribute = $match[1];
                $quote = $match[2];
                $value = $match[3];

                if ($attribute === 'srcset') {
                    $parts = array_map('trim', explode(',', $value));
                    $rewritten = [];

                    foreach ($parts as $part) {
                        if ($part === '') {
                            continue;
                        }

                        $segments = preg_split('/\s+/u', $part, 2);
                        $url = $this->normalizeAssetUrl($segments[0] ?? '', 'https://'.self::TIMAGO_HOST.'/');

                        if ($url === null) {
                            continue;
                        }

                        $rewritten[] = $url.(isset($segments[1]) ? ' '.$segments[1] : '');
                    }

                    return $attribute.'='.$quote.implode(', ', $rewritten).$quote;
                }

                $url = $this->normalizeAssetUrl($value, 'https://'.self::TIMAGO_HOST.'/');

                return $url === null ? $match[0] : $attribute.'='.$quote.$url.$quote;
            },
            $html,
        ) ?? $html;
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        $content = $this->firstAttr($crawler, 'meta[name="'.$name.'"][content]', 'content');

        return is_string($content) && trim($content) !== '' ? $this->normalizeLabel($content) : null;
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        $content = $this->firstAttr($crawler, 'meta[property="'.$property.'"][content]', 'content');

        return is_string($content) && trim($content) !== '' ? $this->normalizeLabel($content) : null;
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $nodes = $crawler->filter($selector);

            if ($nodes->count() === 0) {
                return null;
            }

            $value = $nodes->first()->attr($attribute);

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function firstNodeAttr(Crawler $node, string $attribute): ?string
    {
        try {
            $value = $node->attr($attribute);

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function innerHtml(Crawler $crawler, string $selector): ?string
    {
        try {
            $nodes = $crawler->filter($selector);

            if ($nodes->count() === 0) {
                return null;
            }

            return $this->innerHtmlFromNode($nodes->first());
        } catch (Throwable) {
            return null;
        }
    }

    private function innerHtmlFromNode(Crawler $node): ?string
    {
        $domNode = $node->getNode(0);

        if (! $domNode instanceof DOMElement) {
            return null;
        }

        $html = '';

        foreach ($domNode->childNodes as $child) {
            $html .= $domNode->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html !== '' ? $html : null;
    }

    private function plainTextFromHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return $this->normalizeLabel(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizeLabel(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{00a0}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeAttributeLabel(string $value): string
    {
        $value = $this->normalizeLabel($value);
        $value = trim($value, " \t\n\r\0\x0B:");

        return $value;
    }

    private function normalizePrice(string $value): ?string
    {
        $value = preg_replace('/[^0-9,.]/u', '', $value) ?? '';

        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', str_replace(' ', '', $value));

        if (substr_count($value, '.') > 1) {
            $last = strrpos($value, '.');
            $value = str_replace('.', '', substr($value, 0, (int) $last)).substr($value, (int) $last);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function textAfterLabel(string $text, string $label): ?string
    {
        $position = mb_stripos($text, $label);

        if ($position === false) {
            return null;
        }

        $value = mb_substr($text, $position + mb_strlen($label));
        $value = preg_split('/\s{2,}|\|/u', $value, 2)[0] ?? $value;
        $value = $this->normalizeLabel($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $url = $this->firstSrcsetUrl($url);
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isTimagoUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        return 'https://'.self::TIMAGO_HOST.$path;
    }

    private function firstSrcsetUrl(string $value): string
    {
        $first = trim(explode(',', $value)[0] ?? $value);
        $parts = preg_split('/\s+/u', $first);

        return trim($parts[0] ?? $value);
    }

    private function isPlaceholderImageUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, '/dot.')
            || str_contains($path, 'placeholder')
            || str_contains($path, 'logo')
            || str_contains($path, 'favicon')
            || str_contains($path, '/szablony/public/img/');
    }

    private function isNonCategoryBreadcrumbLabel(string $label, string $name): bool
    {
        $lower = mb_strtolower($label);

        return in_array($lower, ['strona główna', 'oferta', 'produkty'], true)
            || ($name !== '' && mb_strtolower($name) === $lower);
    }

    private function productIdFromUrl(string $url): ?string
    {
        $slug = $this->slugFromUrl($url);

        if ($slug === null) {
            return null;
        }

        if (preg_match('/-(\d+)$/u', $slug, $match) === 1) {
            return $match[1];
        }

        return $slug;
    }

    private function slugFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $basename = basename($path);
        $slug = preg_replace('/\.html$/iu', '', $basename) ?? $basename;

        return $slug !== '' ? $slug : null;
    }

    private function slugify(string $value): ?string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
        $value = trim($value, '-');

        return $value !== '' ? $value : null;
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        if (preg_match('#^(?:javascript|mailto|tel):#iu', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::TIMAGO_HOST.$url;
        } elseif (! preg_match('#^https?://#iu', $url)) {
            $base = $baseUrl ?? 'https://'.self::TIMAGO_HOST.'/';
            $url = rtrim($base, '/').'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = 'https';
        $host = mb_strtolower((string) $parts['host']);
        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$path.$query;
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = '/'.ltrim($path, '/');

        return $path;
    }

    private function isTimagoUrl(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === self::TIMAGO_HOST || $host === 'timago.com';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopTimagoScraper/1.0)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl,en;q=0.9',
        ];
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
}
