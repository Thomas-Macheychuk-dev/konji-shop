<?php

declare(strict_types=1);

namespace App\Services\RehaFund;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class RehaFundProductScraper
{
    private const REHAFUND_HOST = 'sklep.rehafund.pl';

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

        $this->emit('Fetching RehaFund product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch RehaFund product page.';

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
        $productScopeHtml = $this->productScopeHtml($crawler) ?? $html;

        $seoTitle = $this->normalizeText($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $name = $this->extractName($crawler, $seoTitle);
        $descriptionHtml = $this->extractDescriptionHtml($crawler, $productScopeHtml, $name);
        $descriptionPlain = $this->plainTextFromHtml($descriptionHtml) ?? '';
        $categories = $this->extractCategories($crawler, $name);
        $categoryFromContext = $this->categoryNameFromContext($productLinkContext);
        $attributes = $this->extractAttributes($crawler, $productScopeHtml, $descriptionPlain);
        $brand = $this->extractBrand($attributes);
        $sku = $this->extractSku($crawler, $productScopeHtml, $name, $attributes);
        $ean = $this->extractEan($crawler, $productScopeHtml, $attributes);
        $priceGrossAmount = $this->extractPriceGrossAmount($crawler, $productScopeHtml);
        $availabilityLabel = $this->extractAvailabilityLabel($crawler, $productScopeHtml);
        $availability = $this->normalizeAvailability($availabilityLabel, $productScopeHtml);
        $shippingTime = $this->extractShippingTime($crawler, $productScopeHtml);
        $images = $this->extractImages($crawler, $productScopeHtml, $html, $sourceUrl, $name, $sku);
        $variantCandidates = $this->extractVariantCandidates($crawler, $productScopeHtml, $priceGrossAmount);
        $externalProductId = $this->extractExternalProductId($crawler, $productScopeHtml, $canonicalUrl ?? $sourceUrl);

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
            'source' => 'rehafund',
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
            'short_description' => $this->extractShortDescription($seoDescription, $descriptionHtml),
            'description' => $descriptionPlain,
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
        $absolute = $this->normalizeUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isRehaFundUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        if (preg_match('#/3-\d+(?:-\d+)*$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::REHAFUND_HOST.$path;
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
            'source' => 'rehafund',
            'source_url' => $sourceUrl,
            'canonical_url' => null,
            'external_product_id' => $this->productIdFromUrl($sourceUrl),
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => '',
            'brand' => $this->brandPayload('Reha Fund'),
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

    private function productScopeHtml(Crawler $crawler): ?string
    {
        foreach (['.product-presentation-ui', '.product-card-ui', '.product-details-ui', '.product-page-ui', '.main-content-ui', '#main'] as $selector) {
            $html = $this->innerHtml($crawler, $selector);

            if ($html !== null && trim(strip_tags($html)) !== '') {
                return $html;
            }
        }

        return null;
    }

    private function extractName(Crawler $crawler, string $seoTitle): string
    {
        foreach (['h1', '.product-name-ui h1', '.product-name-ui', '[itemprop="name"]'] as $selector) {
            try {
                $name = $this->normalizeText($crawler->filter($selector)->first()->text(''));
            } catch (Throwable) {
                $name = '';
            }

            if ($name !== '' && ! str_contains(mb_strtolower($name), 'kategorie')) {
                return $name;
            }
        }

        $ogTitle = $this->firstMetaPropertyContent($crawler, 'og:title');

        if (is_string($ogTitle) && trim($ogTitle) !== '') {
            return $this->normalizeText(preg_replace('/\s*[-|]\s*Sklep Reha Fund.*$/iu', '', $ogTitle) ?: $ogTitle);
        }

        return $this->normalizeText(preg_replace('/\s*[-|]\s*Sklep Reha Fund.*$/iu', '', $seoTitle) ?: $seoTitle);
    }

    private function extractDescriptionHtml(Crawler $crawler, string $productScopeHtml, string $name): ?string
    {
        foreach ([
            '.product-description-ui',
            '.product-long-description-ui',
            '.long-description-ui',
            '.description-ui',
            '.product-tabs-ui .tab-content-ui',
            '#description',
        ] as $selector) {
            $html = $this->innerHtml($crawler, $selector);

            if ($html !== null && trim(strip_tags($html)) !== '') {
                return $this->cleanDescriptionHtml($html, $name);
            }
        }

        $plain = $this->textBetweenLabels(
            $this->normalizeText(strip_tags($productScopeHtml)),
            'Opis towaru',
            ['Cechy towaru', 'Do pobrania', 'Produkty podobne', 'Produkty powiązane']
        );

        if ($plain !== null && $plain !== '') {
            return '<p>'.htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
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

        foreach (['.breadcrumbs-ui a', '.breadcrumbs a', '.breadcrumb a', '.path a'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$categories, $name): void {
                    $label = $this->normalizeText($node->text(''));

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
    private function extractAttributes(Crawler $crawler, string $productScopeHtml, string $descriptionPlain): array
    {
        $attributes = [];

        foreach (['table tr', '.product-features-ui li', '.features-ui li', '.product-attributes-ui li', '.attributes-ui li', '.product-parameters-ui li'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $row) use (&$attributes): void {
                    $cells = $row->filter('th, td, .name-ui, .value-ui, .label-ui');

                    if ($cells->count() >= 2) {
                        $this->addAttribute($attributes, $cells->eq(0)->text(''), $cells->eq(1)->text(''));

                        return;
                    }

                    $text = $this->normalizeText($row->text(''));
                    $parts = preg_split('/\s*[:–-]\s*/u', $text, 2);

                    if (is_array($parts) && count($parts) === 2) {
                        $this->addAttribute($attributes, $parts[0], $parts[1]);
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        $plain = $this->normalizeText(strip_tags($productScopeHtml).' '.$descriptionPlain);

        foreach (['Podatek VAT', 'Kod EAN', 'Symbol', 'Klasa wyrobu', 'Kod UDI inny LOT', 'Wysokość', 'Długość', 'Szerokość', 'Producent', 'Rozmiar', 'Kolor'] as $label) {
            $value = $this->textAfterLabel($plain, $label, [
                'Podatek VAT', 'Kod EAN', 'Symbol', 'Klasa wyrobu', 'Kod UDI inny LOT', 'Wysokość', 'Długość', 'Szerokość', 'Opis towaru', 'Cechy towaru', 'Do pobrania', 'Dostępność', 'W magazynie', 'Dostawa', 'Rozmiar', 'Kolor', 'Producent',
            ]);

            if ($value !== null) {
                $this->addAttribute($attributes, $label, $value);
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
        $value = $this->normalizeText($value);

        if ($label === '' || $value === '') {
            return;
        }

        if (mb_strlen($value) > 500) {
            return;
        }

        if (in_array(mb_strtolower($label), ['opis', 'opis towaru', 'cechy towaru', 'do pobrania', 'dostępność', 'wysyłka', 'dostawa'], true)) {
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
    private function extractBrand(array $attributes): ?array
    {
        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'], ['producent', 'manufacturer', 'brand'], true)) {
                return $this->brandPayload($attribute['value']);
            }
        }

        return $this->brandPayload('Reha Fund');
    }

    /**
     * @return array{name: string, slug: string|null}
     */
    private function brandPayload(string $name): array
    {
        $name = $this->normalizeText($name);

        return [
            'name' => $name,
            'slug' => $this->slugify($name),
        ];
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractSku(Crawler $crawler, string $productScopeHtml, string $name, array $attributes): ?string
    {
        foreach (['meta[itemprop="sku"][content]', 'input[name="product_code"][value]', '[data-product-code]'] as $selector) {
            $attribute = str_contains($selector, 'content]') ? 'content' : (str_contains($selector, 'value]') ? 'value' : 'data-product-code');
            $sku = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($sku) && trim($sku) !== '') {
                return trim($sku);
            }
        }

        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'], ['symbol', 'kod-produktu', 'kod-towaru', 'sku'], true)) {
                return $attribute['value'];
            }
        }

        $plain = $this->normalizeText(strip_tags($productScopeHtml));

        if ($name !== '') {
            $afterName = $this->textAfterLabel($plain, $name, ['360°', 'Dostępność', 'W magazynie', 'Dostawa', 'Rozmiar', 'Podatek VAT', 'Opis towaru', 'Cechy towaru']);

            if ($afterName !== null && $this->looksLikeSku($afterName)) {
                return $afterName;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractEan(Crawler $crawler, string $productScopeHtml, array $attributes): ?string
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

        if (preg_match('/\b(?:EAN|GTIN|Kod EAN)\s*[:\-]?\s*([0-9]{8,14})\b/iu', strip_tags($productScopeHtml), $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function extractPriceGrossAmount(Crawler $crawler, string $productScopeHtml): ?string
    {
        foreach (['meta[property="product:price:amount"][content]', '[itemprop="price"][content]', '[data-price]'] as $selector) {
            $attribute = str_contains($selector, 'content]') ? 'content' : 'data-price';
            $price = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($price) && trim($price) !== '') {
                return $this->normalizePrice($price);
            }
        }

        $scopeCrawler = new Crawler($productScopeHtml);

        foreach (['.product-price-ui', '.product-price', '.product-price-value-ui', '.price-value-ui', '.price-ui', '.price'] as $selector) {
            try {
                $priceText = $this->normalizeText($scopeCrawler->filter($selector)->first()->text(''));
            } catch (Throwable) {
                $priceText = '';
            }

            if ($priceText === '') {
                continue;
            }

            $price = $this->normalizeVisibleProductPrice($priceText);

            if ($price !== null) {
                return $price;
            }
        }

        $plain = $this->normalizeText(strip_tags($productScopeHtml));

        if (preg_match_all(
            '/(?:Cena|Cena brutto|Cena produktu|Wartość brutto)\s*[:\-]?\s*([0-9]+(?:[\s.][0-9]{3})*(?:[,.][0-9]{1,2})?)\s*(?:zł|PLN)/iu',
            $plain,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) > 0) {
            foreach ($matches[1] as $match) {
                $candidate = $this->priceCandidateWithContext($plain, (int) $match[1], mb_strlen((string) $match[0]));
                $price = $this->normalizeVisibleProductPrice($candidate);

                if ($price !== null) {
                    return $price;
                }
            }
        }

        return null;
    }

    private function normalizeVisibleProductPrice(string $text): ?string
    {
        $text = $this->normalizeText($text);

        if ($text === '') {
            return null;
        }

        $lower = mb_strtolower($text);

        if (str_contains($lower, 'zaloguj')) {
            return null;
        }

        foreach ([
            'darmowa dostawa',
            'dostawa',
            'wysyłka',
            'transport',
            'przesyłka',
            'kurier',
            'paczkomat',
            'odbiór',
            'już od',
            'powyżej',
            'od kwoty',
        ] as $shippingNeedle) {
            if (str_contains($lower, $shippingNeedle)) {
                return null;
            }
        }

        if (preg_match('/([0-9]+(?:[\s.][0-9]{3})*(?:[,.][0-9]{1,2})?)\s*(?:zł|PLN)?/iu', $text, $match) !== 1) {
            return null;
        }

        return $this->normalizePrice($match[1]);
    }

    private function priceCandidateWithContext(string $text, int $offset, int $length): string
    {
        $start = max(0, $offset - 80);
        $contextLength = $length + ($offset - $start) + 80;

        return mb_substr($text, $start, $contextLength);
    }

    private function extractAvailabilityLabel(Crawler $crawler, string $productScopeHtml): ?string
    {
        foreach (['.availability-ui', '.product-availability-ui', '.stock-ui', '[itemprop="availability"]'] as $selector) {
            try {
                $label = $this->normalizeText($crawler->filter($selector)->first()->text(''));
            } catch (Throwable) {
                $label = '';
            }

            if ($label !== '') {
                return $label;
            }
        }

        $plain = $this->normalizeText(strip_tags($productScopeHtml));

        if (preg_match('/Dostępność\s*:\s*([^\n\r]+?)(?:\s+W magazynie|\s+Dostawa|\s+Rozmiar|\s+Zaloguj|$)/iu', $plain, $match) === 1) {
            return $this->normalizeText($match[1]);
        }

        return null;
    }

    private function normalizeAvailability(?string $label, string $productScopeHtml): string
    {
        $text = mb_strtolower($this->normalizeText(($label ?? '').' '.strip_tags($productScopeHtml)));

        if (str_contains($text, 'od ręki') || str_contains($text, 'w magazynie') || str_contains($text, 'dostępny') || str_contains($text, 'dużo')) {
            return 'in_stock';
        }

        if (str_contains($text, 'brak') || str_contains($text, 'niedostęp')) {
            return 'out_of_stock';
        }

        if (str_contains($text, 'zapytaj') || str_contains($text, 'na zamówienie')) {
            return 'on_request';
        }

        return 'unknown';
    }

    private function extractShippingTime(Crawler $crawler, string $productScopeHtml): ?string
    {
        foreach (['.delivery-time-ui', '.shipping-time-ui', '.product-delivery-ui'] as $selector) {
            try {
                $label = $this->normalizeText($crawler->filter($selector)->first()->text(''));
            } catch (Throwable) {
                $label = '';
            }

            if ($label !== '') {
                return $label;
            }
        }

        $plain = $this->normalizeText(strip_tags($productScopeHtml));

        if (preg_match('/(?:Dostawa|Przewidywany czas dostawy)\s*[:\-]?\s*([^\n\r]+?)(?:\s+Towar|\s+Zapytaj|\s+Podatek VAT|$)/iu', $plain, $match) === 1) {
            return $this->normalizeText($match[1]);
        }

        return null;
    }

    /**
     * @return array<int, array{url: string, alt: string|null, sort_order: int}>
     */
    private function extractImages(Crawler $crawler, string $productScopeHtml, string $fullHtml, string $sourceUrl, string $name, ?string $sku): array
    {
        $images = [];
        $sortOrder = 0;

        foreach (['.product-presentation-ui img', '.product-gallery-ui img', '.product-images-ui img', '.gallery-ui img'] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            if ($nodes->count() === 0) {
                continue;
            }

            $nodes->each(function (Crawler $image) use (&$images, &$sortOrder, $sourceUrl, $name): void {
                foreach (['data-lazy', 'data-src', 'data-original', 'data-large', 'data-full-image', 'data-image', 'srcset', 'src'] as $attribute) {
                    $candidate = $image->attr($attribute);

                    if (! is_string($candidate) || trim($candidate) === '') {
                        continue;
                    }

                    foreach ($this->imageCandidatesFromAttributeValue($candidate) as $candidateUrl) {
                        $this->addImage($images, $sortOrder, $candidateUrl, $sourceUrl, $this->normalizeText((string) $image->attr('alt')), $name);
                    }
                }
            });

            if ($images !== []) {
                $filtered = $this->filterImagesForProduct($images, $sourceUrl, $name, $sku);

                if ($filtered !== []) {
                    return $filtered;
                }

                $images = [];
                $sortOrder = 0;
            }
        }

        $this->extractImagesFromHtml($productScopeHtml, $sourceUrl, $name, $images, $sortOrder, $sku, true);

        if ($images === []) {
            $this->extractImagesFromHtml($fullHtml, $sourceUrl, $name, $images, $sortOrder, $sku, true);
        }

        if ($images === []) {
            $this->extractImagesFromFileIdsAndAltText($fullHtml, $sourceUrl, $name, $images, $sortOrder, $sku);
        }

        return $this->resetImageSortOrder(array_values($images));
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, sort_order: int}>  $images
     */
    private function extractImagesFromHtml(string $html, string $sourceUrl, string $name, array &$images, int &$sortOrder, ?string $sku = null, bool $onlyProductRelevant = false): void
    {
        if (preg_match_all("#<(?:a|img|source)\\b[^>]*(?:href|src|srcset|data-lazy|data-src|data-original|data-large|data-full-image|data-image|data-zoom-image|data-large-image|data-full)=([\"'])(.*?)\\1[^>]*>#isu", $html, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $tag = $match[0] ?? '';
                $attributeValue = $match[2] ?? '';
                $alt = '';

                if (preg_match("#\\b(?:alt|title)=([\"'])(.*?)\\1#isu", $tag, $altMatch) === 1) {
                    $alt = $this->normalizeText(html_entity_decode($altMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }

                foreach ($this->imageCandidatesFromAttributeValue($attributeValue) as $candidateUrl) {
                    if ($onlyProductRelevant && ! $this->imageCandidateMatchesProduct($candidateUrl, $alt, $sourceUrl, $name, $sku)) {
                        continue;
                    }

                    $this->addImage($images, $sortOrder, $candidateUrl, $sourceUrl, $alt, $name);
                }
            }
        }

        foreach ($this->imageCandidatesFromRawHtml($html) as $candidateUrl) {
            if ($onlyProductRelevant && ! $this->imageCandidateMatchesProduct($candidateUrl, '', $sourceUrl, $name, $sku)) {
                continue;
            }

            $this->addImage($images, $sortOrder, $candidateUrl, $sourceUrl, '', $name);
        }
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, sort_order: int}>  $images
     */
    private function extractImagesFromFileIdsAndAltText(string $html, string $sourceUrl, string $name, array &$images, int &$sortOrder, ?string $sku = null): void
    {
        $imageAlts = [];

        if (preg_match_all("#\\b(?:alt|title)=([\"'])([^\"']*(?:siedzisko|wozek|wózek|poduszka|materac|balkonik|chodzik|podnosnik|podnośnik|rehafund|rf-)[^\"']*)\\1#isu", $html, $altMatches) > 0) {
            foreach ($altMatches[2] as $alt) {
                $alt = $this->normalizeText(html_entity_decode((string) $alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($alt !== '' && mb_strlen($alt) <= 120) {
                    $imageAlts[] = $alt;
                }
            }
        }

        if ($imageAlts === []) {
            return;
        }

        $fileIds = [];

        if (preg_match_all('#/(?:img|file)/(?:large|medium|preview|compact|original/)?/?(\d{4,})#iu', $html, $idMatches) > 0) {
            foreach ($idMatches[1] as $id) {
                $fileIds[] = (string) $id;
            }
        }

        $fileIds = array_values(array_unique($fileIds));

        if ($fileIds === []) {
            return;
        }

        foreach ($imageAlts as $index => $alt) {
            $fileId = $fileIds[$index] ?? $fileIds[0] ?? null;

            if ($fileId === null) {
                continue;
            }

            $candidate = '/img/large/'.$fileId.'/'.$this->slugify($alt);

            if (! $this->imageCandidateMatchesProduct($candidate, $alt, $sourceUrl, $name, $sku)) {
                continue;
            }

            $this->addImage($images, $sortOrder, $candidate, $sourceUrl, $alt, $name);
        }
    }

    /**
     * @return array<int, string>
     */
    private function imageCandidatesFromRawHtml(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace(['\\/', '\/'], '/', $html);
        $candidates = [];

        if (preg_match_all("#(?:https?:)?//sklep\\.rehafund\\.pl/(?:img|file)/(?:large|medium|compact|preview|original)?/?[^\"'\\s<>]+#iu", $html, $absoluteMatches) > 0) {
            foreach ($absoluteMatches[0] as $candidate) {
                $candidates[] = str_starts_with((string) $candidate, '//') ? 'https:'.$candidate : (string) $candidate;
            }
        }

        if (preg_match_all("#(?<![a-z0-9])/(?:img|file)/(?:large|medium|compact|preview|original)?/?[^\"'\\s<>]+#iu", $html, $relativeMatches) > 0) {
            foreach ($relativeMatches[0] as $candidate) {
                $candidates[] = (string) $candidate;
            }
        }

        if (preg_match_all("#(?<![a-z0-9])img/(?:large|medium|compact|preview|original)/[^\"'\\s<>]+#iu", $html, $relativeWithoutSlashMatches) > 0) {
            foreach ($relativeWithoutSlashMatches[0] as $candidate) {
                $candidates[] = (string) $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<int, string>
     */
    private function imageCandidatesFromAttributeValue(string $value): array
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($value === '') {
            return [];
        }

        $candidates = [];

        foreach (explode(',', $value) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $segments = preg_split('/\s+/u', $part, 2);
            $candidate = trim((string) ($segments[0] ?? ''));

            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, sort_order: int}>  $images
     */
    private function addImage(array &$images, int &$sortOrder, string $candidate, string $sourceUrl, string $alt, string $name): void
    {
        $url = $this->normalizeAssetUrl($candidate, $sourceUrl);

        if ($url === null || ! $this->looksLikeProductImageUrl($url)) {
            return;
        }

        if (isset($images[$url])) {
            return;
        }

        $images[$url] = [
            'url' => $url,
            'alt' => $alt !== '' ? $alt : ($name !== '' ? $name : null),
            'sort_order' => $sortOrder++,
        ];
    }


    /**
     * @param  array<string, array{url: string, alt: string|null, sort_order: int}>  $images
     * @return array<int, array{url: string, alt: string|null, sort_order: int}>
     */
    private function filterImagesForProduct(array $images, string $sourceUrl, string $name, ?string $sku): array
    {
        $filtered = [];

        foreach ($images as $image) {
            $url = (string) ($image['url'] ?? '');
            $alt = (string) ($image['alt'] ?? '');

            if ($this->imageCandidateMatchesProduct($url, $alt, $sourceUrl, $name, $sku)) {
                $filtered[] = $image;
            }
        }

        return $this->resetImageSortOrder($filtered);
    }

    /**
     * @param  array<int, array{url: string, alt: string|null, sort_order: int}>  $images
     * @return array<int, array{url: string, alt: string|null, sort_order: int}>
     */
    private function resetImageSortOrder(array $images): array
    {
        foreach ($images as $index => $image) {
            $images[$index]['sort_order'] = $index;
        }

        return $images;
    }

    private function imageCandidateMatchesProduct(string $candidate, string $alt, string $sourceUrl, string $name, ?string $sku): bool
    {
        $haystack = mb_strtolower(html_entity_decode($candidate.' '.$alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $haystack = str_replace(['_', '+', '%20'], '-', $haystack);
        $haystack = str($haystack)->ascii()->lower()->toString();

        $sourceSlug = $this->slugFromUrl($sourceUrl);
        $nameSlug = $this->slugify($name);
        $skuSlug = $sku === null ? null : $this->slugify($sku);
        $needles = [];

        foreach ([$sourceSlug, $nameSlug] as $slug) {
            if (is_string($slug) && $slug !== '') {
                $needles[] = $slug;
                $needles = array_merge($needles, $this->significantSlugPhrases($slug));
            }
        }

        if (is_string($skuSlug) && $skuSlug !== '') {
            $needles[] = $skuSlug;
            $needles = array_merge($needles, $this->skuImageNeedles($skuSlug));
        }

        $needles = array_values(array_unique(array_filter($needles, static fn (string $needle): bool => mb_strlen($needle) >= 4)));

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function significantSlugPhrases(string $slug): array
    {
        $tokens = array_values(array_filter(explode('-', $slug), static function (string $token): bool {
            return mb_strlen($token) >= 3 && ! in_array($token, ['kolor', 'szer', 'cm', 'roz', 'dla', 'oraz', 'the'], true);
        }));

        if ($tokens === []) {
            return [];
        }

        $phrases = [];

        foreach ([6, 5, 4] as $length) {
            if (count($tokens) >= $length) {
                $phrases[] = implode('-', array_slice($tokens, 0, $length));
            }
        }

        if (count($tokens) >= 4) {
            $phrases[] = implode('-', array_slice($tokens, -4));
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @return array<int, string>
     */
    private function skuImageNeedles(string $skuSlug): array
    {
        $tokens = array_values(array_filter(explode('-', $skuSlug), static fn (string $token): bool => mb_strlen($token) >= 4));

        return array_values(array_unique($tokens));
    }

    /**
     * @return array<int, array{name: string, sku: string|null, price_gross_amount: string|null, currency: string, availability: string, attributes: array<int, array{code: string, label: string, value: string, slug: string|null}>}>
     */
    private function extractVariantCandidates(Crawler $crawler, string $productScopeHtml, ?string $priceGrossAmount): array
    {
        $variants = [];

        foreach (['select[name], select[id]'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $select) use (&$variants, $priceGrossAmount): void {
                    $label = $this->normalizeText((string) $select->attr('name')) ?: $this->normalizeText((string) $select->attr('id')) ?: 'Wariant';

                    $select->filter('option')->each(function (Crawler $option) use (&$variants, $priceGrossAmount, $label): void {
                        $value = $this->normalizeText($option->text(''));

                        if ($value === '' || str_contains(mb_strtolower($value), 'wybierz')) {
                            return;
                        }

                        $this->addVariant($variants, $label, $value, $priceGrossAmount, $this->firstNodeAttr($option, 'data-code') ?: null);
                    });
                });
            } catch (Throwable) {
                continue;
            }
        }

        try {
            $crawler->filter('[data-attribute-name], .product-attributes-values-ui, .attribute-values-ui')->each(function (Crawler $group) use (&$variants, $priceGrossAmount): void {
                $label = $this->normalizeText((string) $group->attr('data-attribute-name')) ?: 'Wariant';

                $group->filter('button, a, label, span')->each(function (Crawler $node) use (&$variants, $priceGrossAmount, $label): void {
                    $value = $this->normalizeText($node->text(''));

                    if ($value !== '' && mb_strlen($value) <= 80) {
                        $this->addVariant($variants, $label, $value, $priceGrossAmount, null);
                    }
                });
            });
        } catch (Throwable) {
            // Keep fallback below.
        }

        if ($variants === []) {
            $plain = $this->normalizeText(strip_tags($productScopeHtml));

            if (preg_match('/\b(Rozmiar|Kolor)\s+((?:[A-Z0-9+\-]{1,12}\s*){1,12})(?:\s+Zaloguj|\s+Podatek VAT|\s+Opis towaru|$)/u', $plain, $match) === 1) {
                $label = $this->normalizeText($match[1]);
                $values = preg_split('/\s+/u', trim($match[2])) ?: [];

                foreach ($values as $value) {
                    $this->addVariant($variants, $label, $value, $priceGrossAmount, null);
                }
            }
        }

        return array_values($variants);
    }

    /**
     * @param  array<string, array{name: string, sku: string|null, price_gross_amount: string|null, currency: string, availability: string, attributes: array<int, array{code: string, label: string, value: string, slug: string|null}>}>  $variants
     */
    private function addVariant(array &$variants, string $label, string $value, ?string $priceGrossAmount, ?string $sku): void
    {
        $label = $this->normalizeAttributeLabel($label);
        $value = $this->normalizeText($value);

        if ($label === '' || $value === '') {
            return;
        }

        if (in_array(mb_strtolower($value), ['tak', 'nie'], true)) {
            return;
        }

        $key = mb_strtolower($label.'|'.$value);

        if (isset($variants[$key])) {
            return;
        }

        $variants[$key] = [
            'name' => $value,
            'sku' => $sku,
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
    }

    private function extractExternalProductId(Crawler $crawler, string $productScopeHtml, string $url): string
    {
        foreach (['input[name="product_id"][value]', '[data-product-id]', 'meta[itemprop="productID"][content]'] as $selector) {
            $attribute = str_contains($selector, 'content]') ? 'content' : (str_contains($selector, 'value]') ? 'value' : 'data-product-id');
            $id = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($id) && trim($id) !== '') {
                return trim($id);
            }
        }

        if (preg_match('#/3-\d+-(\d+)$#', (string) parse_url($url, PHP_URL_PATH), $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#/(?:img/(?:large|compact|medium|preview)|file)/(\d+)/#u', $productScopeHtml, $match) === 1) {
            return $match[1];
        }

        return $this->productIdFromUrl($url) ?? $this->slugFromUrl($url) ?? sha1($url);
    }

    private function isMedicalDevice(string $html, array $categories, array $attributes): bool
    {
        $text = mb_strtolower($this->normalizeText(strip_tags($html)).' '.implode(' ', $categories));

        return str_contains($text, 'wyrób medyczny')
            || str_contains($text, 'wyrobami medycznymi')
            || str_contains($text, 'medyczn')
            || str_contains($text, 'klasa wyrobu');
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
            return $this->normalizeText($seoDescription);
        }

        $plain = $this->plainTextFromHtml($descriptionHtml) ?? '';

        if ($plain === '') {
            return null;
        }

        return mb_substr($plain, 0, 500);
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
                        $url = $this->normalizeAssetUrl($segments[0] ?? '', 'https://'.self::REHAFUND_HOST.'/');

                        if ($url === null) {
                            continue;
                        }

                        $rewritten[] = $url.(isset($segments[1]) ? ' '.$segments[1] : '');
                    }

                    return $attribute.'='.$quote.implode(', ', $rewritten).$quote;
                }

                $url = $this->normalizeAssetUrl($value, 'https://'.self::REHAFUND_HOST.'/');

                return $url === null ? $match[0] : $attribute.'='.$quote.$url.$quote;
            },
            $html,
        ) ?? $html;
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        $content = $this->firstAttr($crawler, 'meta[name="'.$name.'"][content]', 'content');

        return is_string($content) && trim($content) !== '' ? $this->normalizeText($content) : null;
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        $content = $this->firstAttr($crawler, 'meta[property="'.$property.'"][content]', 'content');

        return is_string($content) && trim($content) !== '' ? $this->normalizeText($content) : null;
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

        return $this->normalizeText(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{00a0}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeAttributeLabel(string $value): string
    {
        $value = $this->normalizeText($value);
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
            $lastDot = strrpos($value, '.');
            $value = str_replace('.', '', substr($value, 0, $lastDot)).substr($value, $lastDot);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
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
            $url = 'https://'.self::REHAFUND_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            // RehaFund has a <base href="//sklep.rehafund.pl/">; relative URLs resolve from the site root.
            $url = 'https://'.self::REHAFUND_HOST.'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.$host.$path.$query;
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $absolute = $this->normalizeUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isRehaFundUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        return 'https://'.self::REHAFUND_HOST.$path;
    }

    private function looksLikeProductImageUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        if (! preg_match('/\.(?:jpe?g|png|webp|gif|svg)$/iu', $path) && ! str_contains($path, '/img/')) {
            return false;
        }

        if (str_contains($path, '/css/img/') || str_contains($path, '/logo') || str_contains($path, '/bnr/') || str_contains($path, 'favicon') || str_contains($path, 'alo.gif')) {
            return false;
        }

        return str_contains($path, '/img/large/')
            || str_contains($path, '/img/compact/')
            || str_contains($path, '/img/medium/')
            || str_contains($path, '/img/preview/')
            || str_contains($path, '/img/original/')
            || preg_match('#/img/\d{4,}/#', $path) === 1
            || str_contains($path, '/file/');
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function isRehaFundUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::REHAFUND_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            self::REHAFUND_HOST => self::REHAFUND_HOST,
            default => null,
        };
    }

    private function productIdFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/3-\d+-(\d+)$#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#/3-(\d+(?:-\d+)*)$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function slugFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        if ($segments === []) {
            return null;
        }

        $segment = (string) $segments[0];

        return $this->slugify($segment);
    }

    private function slugify(string $value): ?string
    {
        $value = $this->normalizeText($value);

        if ($value === '') {
            return null;
        }

        $slug = str($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();

        return $slug !== '' ? $slug : null;
    }

    private function isNonCategoryBreadcrumbLabel(string $label, string $name): bool
    {
        $normalized = mb_strtolower($label);

        return in_array($normalized, ['strona główna', 'produkty', 'sklep'], true)
            || ($name !== '' && $this->normalizeText($label) === $this->normalizeText($name));
    }

    private function textBetweenLabels(string $text, string $startLabel, array $endLabels): ?string
    {
        $start = mb_stripos($text, $startLabel);

        if ($start === false) {
            return null;
        }

        $start += mb_strlen($startLabel);
        $end = mb_strlen($text);

        foreach ($endLabels as $endLabel) {
            $position = mb_stripos($text, $endLabel, $start);

            if ($position !== false && $position < $end) {
                $end = $position;
            }
        }

        $value = trim(mb_substr($text, $start, $end - $start));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, string>  $stopLabels
     */
    private function textAfterLabel(string $text, string $label, array $stopLabels = []): ?string
    {
        $position = mb_stripos($text, $label);

        if ($position === false) {
            return null;
        }

        $start = $position + mb_strlen($label);
        $end = mb_strlen($text);

        foreach ($stopLabels as $stopLabel) {
            if (mb_strtolower($stopLabel) === mb_strtolower($label)) {
                continue;
            }

            $candidate = mb_stripos($text, $stopLabel, $start);

            if ($candidate !== false && $candidate < $end) {
                $end = $candidate;
            }
        }

        $value = $this->normalizeText(mb_substr($text, $start, $end - $start));
        $value = trim($value, " \t\n\r\0\x0B:-");

        return $value !== '' ? $value : null;
    }

    private function looksLikeSku(string $value): bool
    {
        $value = trim($value);

        return $value !== ''
            && mb_strlen($value) <= 80
            && preg_match('/^[\pL0-9][\pL0-9\s_\-.\/+]+$/u', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopRehaFundProductScraper/1.0; +https://konji.pl)',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
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
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
