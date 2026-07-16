<?php

declare(strict_types=1);

namespace App\Services\Vaya;

use Closure;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class VayaProductScraper
{
    private const VAYA_HOST = 'www.vaya.com.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 500;

    private int $maxAttempts = 3;

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

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->maxAttempts = max(1, $attempts);
        $this->retryDelayMilliseconds = max(0, $retryDelayMilliseconds);

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

        $this->emit('Fetching Vaya product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch Vaya product page.';

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
        $canonicalUrl = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href');
        $canonicalUrl = is_string($canonicalUrl) ? ($this->normalizeProductUrl($canonicalUrl, $sourceUrl) ?? $canonicalUrl) : null;

        $seoTitle = $this->normalizeLabel($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $name = $this->extractName($crawler, $seoTitle);
        $descriptionHtml = $this->extractDescriptionHtml($crawler);
        $safetyHtml = $this->extractSafetyHtml($crawler);
        $shortDescription = $this->extractShortDescription($seoDescription, $descriptionHtml);
        $categories = $this->extractCategories($crawler, $name);
        $categoryFromContext = $this->categoryNameFromContext($productLinkContext);
        $attributes = $this->extractAttributes($crawler, $descriptionHtml);
        $brand = $this->extractBrand($crawler, $html, $attributes);
        $sku = $this->extractSku($crawler, $html, $attributes);
        $ean = $this->extractEan($crawler, $html, $attributes);
        $priceGrossAmount = $this->extractPriceGrossAmount($crawler, $html);
        $availabilityLabel = $this->extractAvailabilityLabel($crawler, $html);
        $availability = $this->normalizeAvailability($availabilityLabel, $html);
        $shippingTime = $this->extractShippingTime($crawler, $html);
        $images = $this->extractImages($crawler, $sourceUrl, $name);
        $variantCandidates = $this->extractVariantCandidates($crawler, $priceGrossAmount);
        $externalProductId = $this->extractExternalProductId($crawler, $html, $canonicalUrl ?? $sourceUrl);

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($priceGrossAmount === null) {
            $warnings[] = 'Product gross price was not found.';
        }

        if ($descriptionHtml === null || trim(strip_tags($descriptionHtml)) === '') {
            $warnings[] = 'Product description was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        return $this->withProductLinkContext([
            'source' => 'vaya',
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
            'safety_html' => $safetyHtml,
            'price_gross_amount' => $priceGrossAmount,
            'currency' => $this->extractCurrency($crawler) ?? 'PLN',
            'availability' => $availability,
            'availability_label' => $availabilityLabel,
            'shipping_time' => $shippingTime,
            'stock_quantity' => null,
            'sku' => $sku,
            'ean' => $ean,
            'attributes' => $attributes,
            'images' => $images,
            'tabs' => $this->tabs($descriptionHtml, $attributes, $safetyHtml),
            'variant_candidates' => $variantCandidates,
            'is_medical_device' => $this->isMedicalDevice($html, $categories, $attributes),
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ], $productLinkContext);
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isVayaUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('#^/pl/p/[^/]+/[0-9]+$#u', rawurldecode($path)) !== 1) {
            return null;
        }

        return 'https://'.self::VAYA_HOST.$path;
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        $lastReason = 'Unknown HTTP failure';

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastReason = $exception->getMessage();

                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();

                    continue;
                }

                break;
            }

            if ($response->successful()) {
                unset($failed[$url]);

                return $response->body();
            }

            $lastReason = 'HTTP '.$response->status();

            if ($attempt < $this->maxAttempts && $this->shouldRetry($response)) {
                $this->pauseBeforeRetry();

                continue;
            }

            break;
        }

        $failed[$url] = $lastReason;

        return null;
    }

    private function shouldRetry(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
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
            'source' => 'vaya',
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
            'safety_html' => null,
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
        foreach ([
            'h1[itemprop="name"]',
            '.productdetails h1',
            '#box_productfull h1',
            '.product h1',
            'h1',
        ] as $selector) {
            $name = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($name !== '') {
                return $name;
            }
        }

        $ogTitle = $this->firstMetaPropertyContent($crawler, 'og:title');

        if (is_string($ogTitle) && trim($ogTitle) !== '') {
            return $this->normalizeLabel($ogTitle);
        }

        return $this->normalizeLabel(preg_replace('/\s*[-|]\s*(?:Vaya(?: Medical)?|Sklep medyczny Vaya Medical)\s*$/iu', '', $seoTitle) ?: $seoTitle);
    }

    private function extractDescriptionHtml(Crawler $crawler): ?string
    {
        foreach ([
            '#box_description [itemprop="description"]',
            '#box_description .innerbox',
            '#box_description',
            '#box_productfull .productdesc',
            '#box_productfull .description',
            '#box_productfull .innerbox',
            '.product-description',
            '.productdescription',
            '.tab-content #description',
            '#description',
        ] as $selector) {
            $html = $this->innerHtml($crawler, $selector);

            if ($html !== null && trim(strip_tags($html)) !== '') {
                return $html;
            }
        }

        return null;
    }

    private function extractSafetyHtml(Crawler $crawler): ?string
    {
        foreach ([
            '#box_productsafety .innerbox',
            '#box_productsafety',
        ] as $selector) {
            $html = $this->innerHtml($crawler, $selector);

            if ($html !== null && trim(strip_tags($html)) !== '') {
                return $html;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractCategories(Crawler $crawler, string $name): array
    {
        $categories = [];

        foreach ([
            '.breadcrumbs a',
            '.breadcrumb a',
            '#breadcrumbs a',
            'nav[aria-label="breadcrumb"] a',
            '.path a',
        ] as $selector) {
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

        foreach ([
            '#box_productdata table tr',
            '#box_productdata tr',
            '.productdetails table tr',
            '.product-params table tr',
            '.product-parameters table tr',
            '#box_productfull table tr',
            '#box_description table tr',
            'table tr',
        ] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $row) use (&$attributes): void {
                    $cells = $row->filter('th, td');

                    if ($cells->count() < 2) {
                        return;
                    }

                    $label = $this->normalizeAttributeLabel($cells->eq(0)->text(''));
                    $value = $this->normalizeLabel($cells->eq(1)->text(''));

                    $this->addAttribute($attributes, $label, $value);
                });
            } catch (Throwable) {
                continue;
            }
        }

        if ($descriptionHtml !== null) {
            $plain = $this->plainTextFromHtml($descriptionHtml) ?? '';

            foreach ([
                'Rozmiar',
                'Wymiary',
                'Materiał',
                'Kolor',
                'Model',
                'Producent',
                'Kod produktu',
                'Kod EAN',
            ] as $label) {
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

        if ($label === '' || $value === '') {
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
    private function extractBrand(Crawler $crawler, string $html, array $attributes): ?array
    {
        foreach ([
            'meta[itemprop="brand"][content]' => 'content',
            'meta[property="product:brand"][content]' => 'content',
        ] as $selector => $attribute) {
            $brand = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($brand) && trim($brand) !== '') {
                return $this->brandPayload($brand);
            }
        }

        foreach ([
            '.producer a',
            '.producer',
            '.manufacturer a',
            '.manufacturer',
            'a[href*="/producer/"]',
            'a[href*="/producent/"]',
        ] as $selector) {
            $brand = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($brand !== '' && ! str_contains(mb_strtolower($brand), 'producent')) {
                return $this->brandPayload($brand);
            }
        }

        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'], ['producent', 'manufacturer', 'brand'], true)) {
                return $this->brandPayload($attribute['value']);
            }
        }

        $brand = $this->textAfterLabel($this->normalizeLabel(strip_tags($html)), 'Producent:');

        return $brand !== null ? $this->brandPayload($brand) : null;
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
        foreach ([
            'meta[itemprop="sku"][content]',
            'input[name="product_code"][value]',
            '[data-product-code]',
        ] as $selector) {
            $attribute = str_contains($selector, 'content]') ? 'content' : (str_contains($selector, 'value]') ? 'value' : 'data-product-code');
            $sku = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($sku) && trim($sku) !== '') {
                return trim($sku);
            }
        }

        foreach ($attributes as $attribute) {
            if (in_array($attribute['code'], ['kod-produktu', 'sku', 'kod-katalogowy'], true)) {
                return $attribute['value'];
            }
        }

        return $this->textAfterLabel($this->normalizeLabel(strip_tags($html)), 'Kod produktu:');
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function extractEan(Crawler $crawler, string $html, array $attributes): ?string
    {
        foreach ([
            'meta[itemprop="gtin13"][content]',
            'meta[itemprop="gtin"][content]',
        ] as $selector) {
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

        return $this->textAfterLabel($this->normalizeLabel(strip_tags($html)), 'Kod EAN:');
    }

    private function extractPriceGrossAmount(Crawler $crawler, string $html): ?float
    {
        foreach ([
            'meta[itemprop="price"][content]',
            'meta[property="product:price:amount"][content]',
            'input[name="price"][value]',
        ] as $selector) {
            $attribute = str_contains($selector, 'value]') ? 'value' : 'content';
            $value = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($value)) {
                $price = $this->parseMoney($value);

                if ($price !== null) {
                    return $price;
                }
            }
        }

        foreach ([
            '.main-price',
            '.price .main-price',
            '.price em',
            '.product-price',
            '.price-value',
            '.price',
        ] as $selector) {
            $label = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));
            $price = $this->parseMoney($label);

            if ($price !== null) {
                return $price;
            }
        }

        if (preg_match('/Cena:\s*(?:[^0-9]{0,20})?([0-9][0-9\s.,]*)(?:\s*zł|\s*PLN)?/iu', $this->normalizeLabel(strip_tags($html)), $matches) === 1) {
            return $this->parseMoney($matches[1]);
        }

        return null;
    }

    private function extractCurrency(Crawler $crawler): ?string
    {
        $currency = $this->firstAttr($crawler, 'meta[itemprop="priceCurrency"][content], meta[property="product:price:currency"][content]', 'content');

        return is_string($currency) && trim($currency) !== '' ? mb_strtoupper(trim($currency)) : null;
    }

    private function extractAvailabilityLabel(Crawler $crawler, string $html): ?string
    {
        foreach ([
            '#box_productfull .product-container > .availability .availability .second',
            '#box_productfull .product-container .availability .second',
            '.product-availability',
            '.stock',
            '#box_productfull .availability',
        ] as $selector) {
            $label = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($label !== '') {
                return preg_replace('/^Dostępność:\s*/iu', '', $label) ?: $label;
            }
        }

        return $this->textAfterLabel($this->normalizeLabel(strip_tags($html)), 'Dostępność:');
    }

    private function extractShippingTime(Crawler $crawler, string $html): ?string
    {
        foreach ([
            '#box_productfull .product-container .delivery .second',
            '.delivery',
            '.shipping',
            '.shipping-time',
            '.product-shipping',
        ] as $selector) {
            $label = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($label !== '') {
                return preg_replace('/^Wysyłka w:\s*/iu', '', $label) ?: $label;
            }
        }

        return $this->textAfterLabel($this->normalizeLabel(strip_tags($html)), 'Wysyłka w:');
    }

    /**
     * @return array<int, array{url: string, alt: string|null}>
     */
    private function extractImages(Crawler $crawler, string $baseUrl, string $name): array
    {
        $originalImages = [];

        try {
            $crawler->filter('#box_productfull link[itemprop="image"][href], link[itemprop="image"][href]')
                ->each(function (Crawler $node) use (&$originalImages, $baseUrl, $name): void {
                    $value = $node->attr('href');

                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    foreach ($this->imageUrlsFromValue($value, $baseUrl) as $url) {
                        $this->addImage($originalImages, $url, $name !== '' ? $name : null);
                    }
                });
        } catch (Throwable) {
            $originalImages = [];
        }

        if ($originalImages !== []) {
            return array_values($originalImages);
        }

        $images = [];

        foreach ([
            'meta[property="og:image"][content]' => 'content',
            '#box_productfull .productimg img[src]' => 'src',
            '#box_productfull .productimg img[data-src]' => 'data-src',
            '#box_productfull .productimg img[data-src-alt]' => 'data-src-alt',
            '#box_productfull .mainimg img[src]' => 'src',
            '#box_productfull .mainimg img[data-src]' => 'data-src',
            '#box_productfull .gallery img[src]' => 'src',
            '#box_productfull .gallery img[data-src]' => 'data-src',
            '#box_productfull .gallery img[data-src-alt]' => 'data-src-alt',
            '#box_productfull .gallery a[href]' => 'href',
            '.product-gallery img[src]' => 'src',
            '.product-gallery img[data-src]' => 'data-src',
            '.product-gallery img[data-src-alt]' => 'data-src-alt',
            '.product-gallery a[href]' => 'href',
            '.productimg img[src]' => 'src',
            '.productimg img[data-src]' => 'data-src',
            '.productimg img[data-src-alt]' => 'data-src-alt',
            '.mainimg img[src]' => 'src',
            '.mainimg img[data-src]' => 'data-src',
            'a[data-gallery]' => 'data-gallery',
            'a[data-src]' => 'data-src',
            'img[itemprop="image"][src]' => 'src',
            'img[itemprop="image"][data-src]' => 'data-src',
        ] as $selector => $attribute) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $attribute, $baseUrl, $name): void {
                    $value = $node->attr($attribute);

                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    foreach ($this->imageUrlsFromValue($value, $baseUrl) as $url) {
                        if (str_ends_with(mb_strtolower((string) parse_url($url, PHP_URL_PATH)), '/libraries/images/1px.gif')) {
                            continue;
                        }

                        $alt = $this->normalizeLabel((string) ($node->attr('alt') ?? '')) ?: ($name !== '' ? $name : null);
                        $this->addImage($images, $url, $alt);
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        return array_values($images);
    }

    /**
     * @return array<int, string>
     */
    private function imageUrlsFromValue(string $value, string $baseUrl): array
    {
        $values = [$value];
        $decoded = json_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (is_string($entry)) {
                    $values[] = $entry;
                } elseif (is_array($entry)) {
                    foreach (['src', 'href', 'url', 'large'] as $key) {
                        if (is_string($entry[$key] ?? null)) {
                            $values[] = $entry[$key];
                        }
                    }
                }
            }
        }

        $urls = [];

        foreach ($values as $candidate) {
            $url = $this->normalizeAssetUrl($candidate, $baseUrl);

            if ($url !== null) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * @param  array<string, array{url: string, alt: string|null}>  $images
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractVariantCandidates(Crawler $crawler, ?float $priceGrossAmount): array
    {
        $variants = [];

        foreach ([
            'select[name^="option"]',
            'select[name^="product_options"]',
            'select.product-option',
            '.product-options select',
        ] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $select) use (&$variants, $priceGrossAmount): void {
                    $label = $this->variantAttributeLabel($select);

                    $select->filter('option')->each(function (Crawler $option) use (&$variants, $label, $priceGrossAmount): void {
                        $value = trim((string) ($option->attr('value') ?? ''));
                        $text = $this->normalizeLabel($option->text(''));

                        if ($value === '' || $text === '' || str_contains(mb_strtolower($text), 'wybierz')) {
                            return;
                        }

                        $variants[] = [
                            'external_variant_id' => $value,
                            'sku' => null,
                            'label' => $text,
                            'attributes' => [
                                [
                                    'label' => $label,
                                    'value' => $text,
                                ],
                            ],
                            'price_gross_amount' => $priceGrossAmount,
                            'currency' => 'PLN',
                        ];
                    });
                });
            } catch (Throwable) {
                continue;
            }
        }

        return $variants;
    }

    private function variantAttributeLabel(Crawler $select): string
    {
        $id = $select->attr('id');

        if (is_string($id) && $id !== '') {
            $document = $select->getNode(0)?->ownerDocument;

            if ($document instanceof DOMDocument) {
                $xpath = new \DOMXPath($document);
                $label = $xpath->query('//label[@for="'.$id.'"]')->item(0);

                if ($label instanceof DOMElement) {
                    $text = $this->normalizeAttributeLabel($label->textContent ?? '');

                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        $name = $select->attr('name');

        return is_string($name) && trim($name) !== '' ? $this->normalizeAttributeLabel($name) : 'Opcja';
    }

    private function extractExternalProductId(Crawler $crawler, string $html, string $url): ?string
    {
        foreach ([
            'input[name="product_id"][value]' => 'value',
            'input[name="product"][value]' => 'value',
            '[data-product-id]' => 'data-product-id',
            '[data-product]' => 'data-product',
            'meta[itemprop="productID"][content]' => 'content',
        ] as $selector => $attribute) {
            $value = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($value) && preg_match('/\d+/u', $value, $matches) === 1) {
                return $matches[0];
            }
        }

        foreach ([
            '/product_id["\']?\s*[:=]\s*["\']?(\d+)/iu',
            '/productId["\']?\s*[:=]\s*["\']?(\d+)/u',
            '/data-product-id=["\'](\d+)["\']/iu',
        ] as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return $matches[1];
            }
        }

        return $this->productIdFromUrl($url);
    }

    private function productIdFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/pl/p/[^/]+/(\d+)(?:/)?$#u', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
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

    private function cleanProductHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/isu', '', $html) ?? $html;
        $html = preg_replace('/\sdata-[a-z0-9_-]+="[^"]*"/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+/u', ' ', $html) ?? $html;

        return trim($html);
    }

    private function plainTextFromHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = $this->normalizeLabel(strip_tags($html));

        return $text !== '' ? $text : null;
    }

    private function extractShortDescription(?string $seoDescription, ?string $descriptionHtml): ?string
    {
        if (is_string($seoDescription) && trim($seoDescription) !== '') {
            return $this->normalizeLabel($seoDescription);
        }

        $text = $this->plainTextFromHtml($descriptionHtml);

        if ($text === null) {
            return null;
        }

        return mb_strlen($text) > 280 ? mb_substr($text, 0, 277).'...' : $text;
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
            $url = 'https://'.self::VAYA_HOST.$url;
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

        return 'https://'.$host.$this->normalizePath((string) ($parts['path'] ?? '/'));
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl);

        if ($normalized === null || ! $this->isVayaUrl($normalized)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($normalized, PHP_URL_PATH));

        if (preg_match('#\.(?:jpe?g|png|gif|webp)(?:$|\?)#iu', $path) !== 1) {
            return null;
        }

        return 'https://'.self::VAYA_HOST.$this->preferOriginalAssetPath($path);
    }

    private function preferOriginalAssetPath(string $path): string
    {
        foreach ([
            '#^/environment/cache/images/(?:[0-9]+_[0-9]+_)?productGfx_([a-f0-9]{32})(?:_[0-9]+_[0-9]+)?\.(jpe?g|png|gif|webp)$#iu',
            '#^/environment/cache/images/productGfx_([a-f0-9]{32})(?:_[0-9]+_[0-9]+)?\.(jpe?g|png|gif|webp)$#iu',
        ] as $pattern) {
            if (preg_match($pattern, $path, $matches) === 1) {
                return '/userdata/public/gfx/'.$matches[1].'.'.mb_strtolower($matches[2]);
            }
        }

        if (preg_match('#^/environment/cache/images/productGfx_([0-9]+)_[0-9]+_[0-9]+/(.+\.(?:jpe?g|png|gif|webp))$#iu', $path, $matches) === 1) {
            return '/userdata/public/gfx/'.$matches[1].'/'.$matches[2];
        }

        return $path;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host === 'vaya.com.pl' ? self::VAYA_HOST : null;
    }

    private function isVayaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::VAYA_HOST;
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function normalizeAttributeLabel(string $label): string
    {
        $label = preg_replace('/[:：]+$/u', '', $this->normalizeLabel($label)) ?: $label;
        $label = str_replace(['_', '-'], ' ', $label);
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function slugFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/pl/p/([^/]+)/(\d+)$#u', $path, $matches) === 1) {
            return $matches[1];
        }

        $slug = basename($path);

        return $slug !== '' ? $slug : null;
    }

    private function slugify(string $value): ?string
    {
        $value = mb_strtolower($this->normalizeLabel($value));
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?: $value;
        $value = trim($value, '-');

        return $value !== '' ? $value : null;
    }

    private function parseMoney(string $value): ?float
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/([0-9]+(?:[\s.][0-9]{3})*(?:[,.][0-9]{1,2})?|[0-9]+[,.][0-9]{1,2})/u', $value, $matches) !== 1) {
            return null;
        }

        $number = str_replace(["\xc2\xa0", ' '], '', $matches[1]);

        if (str_contains($number, ',') && str_contains($number, '.')) {
            $number = str_replace('.', '', $number);
        }

        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? round((float) $number, 2) : null;
    }

    private function textAfterLabel(string $text, string $label): ?string
    {
        $text = $this->normalizeLabel($text);
        $labelPattern = preg_quote($label, '/');

        if (preg_match('/'.$labelPattern.'\s*([^|\n\r]+?)(?=\s{2,}|\s[A-ZŁŚŻŹĆŃÓĘ][A-Za-zŁŚŻŹĆŃÓĘąćęłńóśżź ]{2,}:|$)/u', $text, $matches) !== 1) {
            return null;
        }

        $value = $this->normalizeLabel($matches[1]);
        $value = preg_replace('/\s*(Dostępność|Wysyłka w|Cena|Kod produktu):.*$/u', '', $value) ?: $value;
        $value = trim($value, " \t\n\r\0\x0B:|");

        return $value !== '' ? $value : null;
    }

    private function normalizeAvailability(?string $availabilityLabel, string $html): string
    {
        $labelOnly = mb_strtolower($this->normalizeLabel((string) $availabilityLabel));

        if ($labelOnly !== '') {
            if ($this->containsAny($labelOnly, ['towar niedostępny', 'niedostępny', 'niedostepny', 'brak towaru', 'wyprzedany'])) {
                return 'out_of_stock';
            }

            if ($this->containsAny($labelOnly, ['duża ilość', 'duza ilosc', 'towar dostępny', 'towar dostepny', 'dostępny od ręki', 'dostepny od reki', 'dostępny', 'dostepny', 'wysyłka w', 'wysylka w', '24 godz'])) {
                return 'in_stock';
            }
        }

        $pageText = mb_strtolower($this->normalizeLabel(strip_tags($html)));

        if ($this->containsAny($pageText, ['towar niedostępny', 'brak towaru', 'wyprzedany'])) {
            return 'out_of_stock';
        }

        if ($this->containsAny($pageText, ['duża ilość', 'duza ilosc', 'towar dostępny', 'towar dostepny', 'dostępny od ręki', 'dostepny od reki', 'wysyłka w', 'wysylka w', '24 godz'])) {
            return 'in_stock';
        }

        return 'unknown';
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $categories
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     */
    private function isMedicalDevice(string $html, array $categories, array $attributes): bool
    {
        $haystack = mb_strtolower($this->normalizeLabel(strip_tags($html).' '.implode(' ', $categories).' '.json_encode($attributes, JSON_UNESCAPED_UNICODE)));

        foreach (['wyrób medyczny', 'wyrob medyczny', 'wyrobu medycznego', 'wyrobem medycznym', 'produkt medyczny', 'produktu medycznego', 'sprzęt medyczny', 'sprzet medyczny', 'orteza', 'ciśnieniomierz', 'cisnieniomierz'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isNonCategoryBreadcrumbLabel(string $label, string $productName): bool
    {
        $labelLower = mb_strtolower($label);

        return in_array($labelLower, ['strona główna', 'strona glowna', 'home'], true)
            || ($productName !== '' && mb_strtolower($label) === mb_strtolower($productName));
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function withProductLinkContext(array $product, ?array $context): array
    {
        $categoryPaths = $this->contextCategoryPaths($context);
        $categoryUrls = $this->stringList($context['category_urls'] ?? []);
        $primaryPath = $categoryPaths[0] ?? [];
        $categoryName = $primaryPath !== [] ? $primaryPath[array_key_last($primaryPath)] : null;
        $topCategoryName = $primaryPath[0] ?? null;

        return $product + [
            'source_category_name' => $categoryName,
            'source_category_url' => $categoryUrls[0] ?? null,
            'source_top_category_name' => $topCategoryName,
            'source_top_category_url' => null,
            'source_category_path' => $primaryPath,
            'source_category_paths' => $categoryPaths,
            'source_category_urls' => $categoryUrls,
            'source_product_list_name' => $this->nullableString($context['name'] ?? null),
            'raw_context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function categoryNameFromContext(?array $context): ?string
    {
        $paths = $this->contextCategoryPaths($context);
        $path = $paths[0] ?? [];

        return $path !== [] ? $path[array_key_last($path)] : null;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<int, array<int, string>>
     */
    private function contextCategoryPaths(?array $context): array
    {
        if ($context === null) {
            return [];
        }

        $paths = [];
        $candidates = $context['category_paths'] ?? null;

        if (is_array($candidates)) {
            foreach ($candidates as $candidate) {
                $path = $this->stringList($candidate);

                if ($path !== []) {
                    $paths[json_encode($path, JSON_UNESCAPED_UNICODE)] = $path;
                }
            }
        }

        $singlePath = $this->stringList($context['category_path'] ?? []);

        if ($singlePath !== []) {
            $paths[json_encode($singlePath, JSON_UNESCAPED_UNICODE)] = $singlePath;
        }

        return array_values($paths);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            $strings[] = trim($entry);
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string|null}>  $attributes
     * @return array<string, mixed>
     */
    private function tabs(?string $descriptionHtml, array $attributes, ?string $safetyHtml): array
    {
        $tabs = [];

        if ($descriptionHtml !== null) {
            $tabs['opis'] = $descriptionHtml;
        }

        if ($attributes !== []) {
            $tabs['parametry'] = $attributes;
        }

        if ($safetyHtml !== null) {
            $tabs['bezpieczenstwo'] = $safetyHtml;
        }

        return $tabs;
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

    private function emit(string $message): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($message);
    }
}
