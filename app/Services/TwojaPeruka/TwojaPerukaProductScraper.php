<?php

declare(strict_types=1);

namespace App\Services\TwojaPeruka;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TwojaPerukaProductScraper
{
    private const TWOJAPERUKA_HOST = 'twojaperuka.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

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
        $failed = [];
        $warnings = [];
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;

        $this->emit('Fetching TwojaPeruka product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch TwojaPeruka product page.';

            return $this->emptyResult($sourceUrl, $failed, $warnings);
        }

        $xpath = $this->xpath($html);

        if (! $xpath instanceof DOMXPath) {
            $warnings[] = 'Unable to parse TwojaPeruka product HTML.';

            return $this->emptyResult($sourceUrl, $failed, $warnings);
        }

        $canonicalUrl = $this->extractCanonicalUrl($xpath, $sourceUrl) ?? $sourceUrl;
        $slug = $this->slugFromUrl($canonicalUrl);
        $name = $this->extractProductName($xpath);
        $seoTitle = $this->extractSeoTitle($xpath);
        $seoDescription = $this->extractMetaContent($xpath, 'description');
        $shortDescriptionHtml = $this->extractShortDescriptionHtml($xpath);
        $descriptionHtml = $this->extractDescriptionHtml($xpath);
        $categories = $this->extractBreadcrumbCategories($xpath, $name);
        $price = $this->extractPriceGrossAmount($xpath, $html);
        $currency = $this->extractCurrency($xpath, $html);
        $availabilityLabel = $this->extractAvailabilityLabel($xpath, $html);
        $sku = $this->extractSku($xpath, $html);
        $externalProductId = $this->extractExternalProductId($xpath, $html) ?? $sku ?? $slug;
        $brand = $this->extractBrand($xpath, $html);
        $images = $this->extractImages($xpath, $canonicalUrl, $name);
        $variantOptions = $this->extractVariantOptions($xpath);
        $isMedicalDevice = $this->containsMedicalDeviceNotice($html);

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($price === null) {
            $warnings[] = 'Product gross price was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        return [
            'source' => 'twojaperuka',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $externalProductId,
            'slug' => $slug,
            'name' => $name,
            'brand' => $brand,
            'category' => $categories === [] ? null : $categories[array_key_last($categories)],
            'categories' => $categories,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'short_description' => $this->htmlToText($shortDescriptionHtml) ?: $seoDescription,
            'short_description_html' => $shortDescriptionHtml,
            'description' => $this->htmlToText($descriptionHtml),
            'description_html' => $descriptionHtml,
            'price_gross_amount' => $price,
            'currency' => $currency,
            'availability' => $this->availabilityStatus($availabilityLabel),
            'availability_label' => $availabilityLabel,
            'stock_quantity' => null,
            'sku' => $sku,
            'ean' => $this->extractEan($xpath, $html),
            'images' => $images,
            'variant_options' => $variantOptions,
            'is_medical_device' => $isMedicalDevice,
            'warnings' => $warnings,
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<string, string>  $failed
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function emptyResult(string $sourceUrl, array $failed, array $warnings): array
    {
        return [
            'source' => 'twojaperuka',
            'source_url' => $sourceUrl,
            'canonical_url' => null,
            'external_product_id' => null,
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => '',
            'brand' => null,
            'category' => null,
            'categories' => [],
            'seo_title' => null,
            'seo_description' => null,
            'short_description' => null,
            'short_description_html' => null,
            'description' => '',
            'description_html' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => null,
            'ean' => null,
            'images' => [],
            'variant_options' => [],
            'is_medical_device' => false,
            'warnings' => $warnings,
            'failed_urls' => $failed,
        ];
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

    private function xpath(string $html): ?DOMXPath
    {
        $document = new DOMDocument;

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if (! $loaded) {
            return null;
        }

        return new DOMXPath($document);
    }

    private function extractJsonScalarValue(string $html, string $key): ?string
    {
        $quotedKey = preg_quote($key, '/');

        if (preg_match('/"'.$quotedKey.'"\s*:\s*"([^"]+)"/iu', $html, $matches) === 1) {
            return $this->normalizeLabel($matches[1]);
        }

        if (preg_match('/"'.$quotedKey.'"\s*:\s*([0-9]+(?:[,.][0-9]+)?)/iu', $html, $matches) === 1) {
            return $this->normalizeLabel($matches[1]);
        }

        return null;
    }

    private function extractCanonicalUrl(DOMXPath $xpath, string $baseUrl): ?string
    {
        $node = $this->firstElement($xpath, '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "canonical"][@href]');

        if (! $node instanceof DOMElement) {
            return null;
        }

        return $this->normalizeProductUrl($node->getAttribute('href'), $baseUrl);
    }

    private function extractProductName(DOMXPath $xpath): string
    {
        foreach ([
            '//main//h1[1]',
            '//h1[contains(concat(" ", normalize-space(@class), " "), " product-name ")][1]',
            '//h1[1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            $name = $this->normalizeLabel($node->textContent ?? '');

            if ($name !== '') {
                return $name;
            }
        }

        $ogTitle = $this->extractMetaPropertyContent($xpath, 'og:title');

        return $ogTitle ?? '';
    }

    private function extractSeoTitle(DOMXPath $xpath): ?string
    {
        $node = $this->firstElement($xpath, '//title[1]');

        if (! $node instanceof DOMElement) {
            return null;
        }

        $title = $this->normalizeLabel($node->textContent ?? '');

        return $title === '' ? null : $title;
    }

    private function extractMetaContent(DOMXPath $xpath, string $name): ?string
    {
        $name = mb_strtolower($name);
        $nodes = $xpath->query('//meta[@content and translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "'.$name.'"]');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $content = $this->normalizeLabel($node->getAttribute('content'));

        return $content === '' ? null : $content;
    }

    private function extractMetaPropertyContent(DOMXPath $xpath, string $property): ?string
    {
        $property = mb_strtolower($property);
        $nodes = $xpath->query('//meta[@content and translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "'.$property.'"]');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $content = $this->normalizeLabel($node->getAttribute('content'));

        return $content === '' ? null : $content;
    }

    private function extractShortDescriptionHtml(DOMXPath $xpath): ?string
    {
        foreach ([
            '//*[contains(concat(" ", normalize-space(@class), " "), " product-short-description ")][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " short-description ")][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " product__short-description ")][1]',
            '//*[@id="short-description"][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            $html = $this->innerHtml($node);

            if ($this->htmlToText($html) !== '') {
                return $this->cleanHtml($html);
            }
        }

        return null;
    }

    private function extractDescriptionHtml(DOMXPath $xpath): ?string
    {
        foreach ([
            '//*[@id="description-long"][1]',
            '//*[@id="description"][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " product-description ")][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " product__description ")][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " description-long ")][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " tinymce_html ")][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            $html = $this->innerHtml($node);

            if ($this->htmlToText($html) !== '') {
                return $this->cleanHtml($html);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractBreadcrumbCategories(DOMXPath $xpath, string $productName): array
    {
        $nodes = $xpath->query(
            '//nav[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]//a'
            .' | //ol[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs__list ")]//a'
        );

        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $categories = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $name = $this->normalizeLabel($node->textContent ?? '');

            if ($name === '' || $this->isHomeBreadcrumb($name) || $name === $productName) {
                continue;
            }

            $categories[] = $name;
        }

        return array_values(array_unique($categories));
    }

    private function extractPriceGrossAmount(DOMXPath $xpath, string $html): ?float
    {
        foreach ([
            'product:price:amount',
            'og:price:amount',
        ] as $property) {
            $content = $this->extractMetaPropertyContent($xpath, $property);

            if ($content !== null && ($price = $this->parseMoney($content)) !== null) {
                return $price;
            }
        }

        foreach (['price', 'product_price', 'gross_price'] as $jsonKey) {
            $content = $this->extractJsonScalarValue($html, $jsonKey);

            if ($content !== null && ($price = $this->parseMoney($content)) !== null) {
                return $price;
            }
        }

        foreach ([
            '//main//*[@data-price][1]',
            '//main//*[contains(concat(" ", normalize-space(@class), " "), " price__value ")][1]',
            '//main//*[contains(concat(" ", normalize-space(@class), " "), " product-price ")][1]',
            '//main//*[contains(concat(" ", normalize-space(@class), " "), " product-price__price ")][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['data-price', 'content', 'value'] as $attribute) {
                if ($node->hasAttribute($attribute) && ($price = $this->parseMoney($node->getAttribute($attribute))) !== null) {
                    return $price;
                }
            }

            if (($price = $this->parseMoney($node->textContent ?? '')) !== null) {
                return $price;
            }
        }

        $text = $this->normalizeLabel($this->htmlToText($html));

        if (preg_match('/Cena\s+([0-9\s]+(?:[,.][0-9]{2})?)\s*zł/u', $text, $matches) === 1) {
            return $this->parseMoney($matches[1]);
        }

        return null;
    }

    private function extractCurrency(DOMXPath $xpath, string $html): string
    {
        $currency = $this->extractMetaPropertyContent($xpath, 'product:price:currency')
            ?? $this->extractMetaPropertyContent($xpath, 'og:price:currency');

        if ($currency !== null && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            return $currency;
        }

        return str_contains($html, 'zł') ? 'PLN' : 'PLN';
    }

    private function extractAvailabilityLabel(DOMXPath $xpath, string $html): ?string
    {
        foreach ([
            '//*[contains(concat(" ", normalize-space(@class), " "), " product-availability__image-and-description ")]//strong[1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " product-availability__image-and-description ")][1]',
            '//*[@data-availability][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " availability ")][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " product-availability ")][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['data-availability', 'content'] as $attribute) {
                if ($node->hasAttribute($attribute)) {
                    $value = $this->normalizeLabel($node->getAttribute($attribute));

                    if ($value !== '') {
                        return $this->cleanAvailabilityLabel($value);
                    }
                }
            }

            $label = $this->normalizeLabel($node->textContent ?? '');

            if ($label !== '') {
                return $this->cleanAvailabilityLabel($label);
            }
        }

        $text = $this->normalizeLabel($this->htmlToText($html));

        if (preg_match('/Dostępność:\s*(.+?)(?:\s+Bezpieczeństwo|\s+Opis|\s+Dane techniczne|\s+Dodaj do koszyka|$)/u', $text, $matches) === 1) {
            return $this->cleanAvailabilityLabel($matches[1]);
        }

        return null;
    }

    private function cleanAvailabilityLabel(string $label): string
    {
        $label = $this->normalizeLabel($label);
        $label = preg_replace('/^(?:Dostępność|Availability)\s*:\s*/iu', '', $label) ?? $label;
        $label = preg_replace('/\s*(?:\{\s*"@context"|Bezpieczeństwo|Opis|Dane techniczne|Dodaj do koszyka|Cena|Producent|Kod produktu)\b.*$/iu', '', $label) ?? $label;

        return $this->normalizeLabel($label);
    }

    private function availabilityStatus(?string $label): string
    {
        $normalized = $this->normalizeComparableName((string) $label);

        if ($normalized === '') {
            return 'unknown';
        }

        if (str_contains($normalized, 'brak') || str_contains($normalized, 'niedostep')) {
            return 'out_of_stock';
        }

        if (str_contains($normalized, 'wyczerp')) {
            return 'low_stock';
        }

        if (str_contains($normalized, 'dostep') || str_contains($normalized, 'ilosc') || str_contains($normalized, 'magazyn')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    private function extractExternalProductId(DOMXPath $xpath, string $html): ?string
    {
        foreach ([
            '//input[@name="product_id"][@value][1]',
            '//input[@name="product-id"][@value][1]',
            '//*[@data-product-id][1]',
            '//*[@data-productid][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['value', 'data-product-id', 'data-productid'] as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $id = $this->normalizeLabel($node->getAttribute($attribute));

                if ($id !== '') {
                    return $id;
                }
            }
        }

        if (preg_match('/product[_-]?id["\'\s:=]+([0-9]+)/iu', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractSku(DOMXPath $xpath, string $html): ?string
    {
        foreach ([
            '//meta[@content and translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "sku"][1]',
            '//*[@data-sku][1]',
            '//span[contains(concat(" ", normalize-space(@class), " "), " sku ")][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['content', 'data-sku'] as $attribute) {
                if ($node->hasAttribute($attribute)) {
                    $value = $this->normalizeLabel($node->getAttribute($attribute));

                    if ($value !== '') {
                        return $value;
                    }
                }
            }

            $value = $this->normalizeLabel($node->textContent ?? '');

            if ($value !== '') {
                return preg_replace('/^(SKU|Kod produktu|Kod):\s*/iu', '', $value) ?: $value;
            }
        }

        if (preg_match('/(?:SKU|Kod produktu|Kod):\s*([A-Z0-9._\-\/]+)/iu', $this->htmlToText($html), $matches) === 1) {
            return $this->normalizeLabel($matches[1]);
        }

        return null;
    }

    private function extractEan(DOMXPath $xpath, string $html): ?string
    {
        if (preg_match('/(?:EAN|GTIN):\s*([0-9]{8,14})/iu', $this->htmlToText($html), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractBrand(DOMXPath $xpath, string $html): ?string
    {
        foreach (['producer', 'brand', 'manufacturer'] as $jsonKey) {
            $brand = $this->cleanBrandCandidate($this->extractJsonScalarValue($html, $jsonKey));

            if ($brand !== null) {
                return $brand;
            }
        }

        foreach ([
            '//meta[@content and translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "brand"][1]',
            '//main//*[@data-producer][1]',
            '//main//*[contains(concat(" ", normalize-space(@class), " "), " producer ")][1]',
            '//main//*[contains(concat(" ", normalize-space(@class), " "), " brand ")][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['content', 'data-producer'] as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $brand = $this->cleanBrandCandidate($node->getAttribute($attribute));

                if ($brand !== null) {
                    return $brand;
                }
            }

            $brand = $this->cleanBrandCandidate($node->textContent ?? '');

            if ($brand !== null) {
                return $brand;
            }
        }

        if (preg_match('/(?:Producent|Marka)\s*:?\s*([^\n\r<]{2,80})/iu', $this->htmlToText($html), $matches) === 1) {
            return $this->cleanBrandCandidate($matches[1]);
        }

        if (preg_match('/\bNAH\b/u', $html) === 1) {
            return 'NAH';
        }

        return null;
    }

    private function cleanBrandCandidate(?string $brand): ?string
    {
        if ($brand === null) {
            return null;
        }

        $brand = $this->normalizeLabel($brand);
        $brand = preg_replace('/^(?:Producent|Marka)\s*:\s*/iu', '', $brand) ?? $brand;
        $brand = preg_replace('/\s+(?:Opis|Opinie|Dane techniczne|Bezpieczeństwo|Cena|Dostępność)\b.*$/iu', '', $brand) ?? $brand;
        $brand = $this->normalizeLabel($brand);

        if ($brand === '') {
            return null;
        }

        if (preg_match('/\bNAH\b/u', $brand) === 1) {
            return 'NAH';
        }

        if (mb_strlen($brand) > 80) {
            return null;
        }

        return $brand;
    }

    /**
     * @return array<int, array{url: string, alt: string|null, position: int}>
     */
    private function extractImages(DOMXPath $xpath, string $baseUrl, string $productName): array
    {
        $images = [];

        $ogImage = $this->extractMetaPropertyContent($xpath, 'og:image');

        if ($ogImage !== null) {
            $this->addImage($images, $ogImage, $baseUrl, $productName);
        }

        $fullSizeAnchors = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " product-gallery ")]'
            .'//a[contains(concat(" ", normalize-space(@class), " "), " js__gallery-anchor-image ")][@href]'
        );

        if ($fullSizeAnchors !== false) {
            foreach ($fullSizeAnchors as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }

                $image = $this->firstElementFromContext($xpath, './/img[1]', $anchor);
                $alt = $image instanceof DOMElement ? $this->normalizeLabel($image->getAttribute('alt')) : null;

                $this->addImage($images, $anchor->getAttribute('href'), $baseUrl, $alt ?: $productName);
            }
        }

        if ($this->hasUsefulImages($images)) {
            return $this->formatImages($images);
        }

        $galleryScope = '//*[contains(concat(" ", normalize-space(@class), " "), " product-gallery ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " product__gallery ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " product-photos ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " product-slider ")]';

        $nodes = $xpath->query('('.$galleryScope.')//img[@src or @data-src or @data-original or @srcset]');

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $alt = $this->normalizeLabel($node->getAttribute('alt'));

                foreach (['data-src', 'data-original', 'src'] as $attribute) {
                    if ($node->hasAttribute($attribute)) {
                        $this->addImage($images, $node->getAttribute($attribute), $baseUrl, $alt ?: $productName);
                    }
                }

                if ($node->hasAttribute('srcset')) {
                    foreach ($this->srcsetUrls($node->getAttribute('srcset')) as $srcsetUrl) {
                        $this->addImage($images, $srcsetUrl, $baseUrl, $alt ?: $productName);
                    }
                }
            }
        }

        $sourceNodes = $xpath->query('('.$galleryScope.')//source[@srcset]');

        if ($sourceNodes !== false) {
            foreach ($sourceNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                foreach ($this->srcsetUrls($node->getAttribute('srcset')) as $srcsetUrl) {
                    $this->addImage($images, $srcsetUrl, $baseUrl, $productName);
                }
            }
        }

        return $this->formatImages($images);
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, score: int}>  $images
     * @return array<int, array{url: string, alt: string|null, position: int}>
     */
    private function formatImages(array $images): array
    {
        $orderedImages = array_values($images);

        return array_values(array_map(
            static fn (array $image, int $index): array => [
                'url' => $image['url'],
                'alt' => $image['alt'],
                'position' => $index + 1,
            ],
            $orderedImages,
            array_keys($orderedImages),
        ));
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, score: int}>  $images
     */
    private function hasUsefulImages(array $images): bool
    {
        foreach ($images as $image) {
            if (($image['score'] ?? 0) >= 500) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, score: int}>  $images
     */
    private function addImage(array &$images, string $url, string $baseUrl, ?string $alt = null): void
    {
        $normalizedUrl = $this->normalizeAssetUrl($url, $baseUrl);

        if ($normalizedUrl === null || ! $this->looksLikeProductImage($normalizedUrl, $alt)) {
            return;
        }

        $key = $this->canonicalImageKey($normalizedUrl);
        $score = $this->imageQualityScore($normalizedUrl);
        $normalizedAlt = $alt !== null && $this->normalizeLabel($alt) !== '' ? $this->normalizeLabel($alt) : null;

        if (! isset($images[$key]) || $score > $images[$key]['score']) {
            $images[$key] = [
                'url' => $normalizedUrl,
                'alt' => $normalizedAlt,
                'score' => $score,
            ];

            return;
        }

        if ($images[$key]['alt'] === null && $normalizedAlt !== null) {
            $images[$key]['alt'] = $normalizedAlt;
        }
    }

    private function looksLikeProductImage(string $url, ?string $alt): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $pathLower = mb_strtolower($path);

        if (! preg_match('/\.(webp|jpe?g|png)$/i', $pathLower)) {
            return false;
        }

        if (preg_match('#/(?:50|75|100|120|150)_(?:50|75|100|120|150)/#', $pathLower) === 1) {
            return false;
        }

        foreach (['logo', 'icon', 'payment', 'facebook', 'instagram', 'pinterest', 'pwa'] as $blocked) {
            if (str_contains($pathLower, $blocked)) {
                return false;
            }
        }

        $altNormalized = $this->normalizeComparableName((string) $alt);

        return str_contains($pathLower, '/userdata/')
            || str_contains($pathLower, '/productgfx')
            || str_contains($pathLower, '/product')
            || str_contains($altNormalized, 'peruka')
            || str_contains($altNormalized, 'topper')
            || str_contains($altNormalized, 'kucyk')
            || str_contains($altNormalized, 'wlos');
    }

    private function canonicalImageKey(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $pathLower = mb_strtolower($path);

        if (preg_match('~productgfx_([^_/]+)(?:_\d+_\d+)?(?:/([^/?#]+))?~i', $pathLower, $matches) === 1) {
            return 'productgfx:'.$matches[1].':'.($matches[2] ?? '');
        }

        return $pathLower;
    }

    private function imageQualityScore(string $url): int
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#_(\d+)_(\d+)(?:/|\.)#', $path, $matches) === 1) {
            $width = (int) $matches[1];
            $height = (int) $matches[2];

            if ($width === 0 && $height === 0) {
                return 1000;
            }

            return max($width, $height);
        }

        return str_ends_with(mb_strtolower($path), '.webp') ? 750 : 500;
    }

    /**
     * @return array<int, string>
     */
    private function srcsetUrls(string $srcset): array
    {
        $urls = [];

        foreach (explode(',', $srcset) as $part) {
            $url = trim((string) preg_replace('/\s+[0-9.]+[wx]$/', '', trim($part)));

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    private function extractVariantOptions(DOMXPath $xpath): array
    {
        $options = [];

        foreach ($this->productVariantSelects($xpath) as $select) {
            $values = [];

            foreach ($select->getElementsByTagName('option') as $option) {
                $value = $this->normalizeLabel($option->textContent ?? '');

                if ($value === '' || $this->isPlaceholderVariantValue($value)) {
                    continue;
                }

                $values[] = $value;
            }

            if ($values === []) {
                continue;
            }

            $name = $this->labelForField($xpath, $select) ?: $this->normalizeLabel($select->getAttribute('name')) ?: 'wariant';

            if ($this->isStorefrontLocaleOption($name, $values)) {
                continue;
            }

            $options[$this->normalizeComparableName($name)] = [
                'name' => $name,
                'values' => array_values(array_unique($values)),
            ];
        }

        $fieldsets = $xpath->query('//main//fieldset[.//input[@type="radio" or @type="checkbox"]]');

        if ($fieldsets !== false) {
            foreach ($fieldsets as $fieldset) {
                if (! $fieldset instanceof DOMElement) {
                    continue;
                }

                $legend = $this->firstElementFromContext($xpath, './/legend[1]', $fieldset);
                $name = $legend instanceof DOMElement ? $this->normalizeLabel($legend->textContent ?? '') : 'wariant';
                $values = [];
                $labels = $xpath->query('.//label', $fieldset);

                if ($labels === false) {
                    continue;
                }

                foreach ($labels as $label) {
                    if (! $label instanceof DOMElement) {
                        continue;
                    }

                    $value = $this->normalizeLabel($label->textContent ?? '');

                    if ($value !== '' && ! $this->isPlaceholderVariantValue($value)) {
                        $values[] = $value;
                    }
                }

                if ($values !== [] && ! $this->isStorefrontLocaleOption($name, $values)) {
                    $options[$this->normalizeComparableName($name)] = [
                        'name' => $name,
                        'values' => array_values(array_unique($values)),
                    ];
                }
            }
        }

        return array_values($options);
    }

    /**
     * @return array<int, DOMElement>
     */
    private function productVariantSelects(DOMXPath $xpath): array
    {
        $queries = [
            '//main//article//select[option]',
            '//main//form//select[option]',
            '//main//*[contains(concat(" ", normalize-space(@class), " "), " product ")]//select[option]',
        ];

        $selects = [];
        $seen = [];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $hash = spl_object_id($node);

                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $selects[] = $node;
            }
        }

        return $selects;
    }

    private function isPlaceholderVariantValue(string $value): bool
    {
        $normalized = $this->normalizeComparableName($value);

        return $normalized === 'wybierz'
            || $normalized === 'wybierz wariant'
            || str_contains($normalized, 'wybierz wariant produktu')
            || str_contains($normalized, 'wybierz ocene');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function isStorefrontLocaleOption(string $name, array $values): bool
    {
        $normalizedName = $this->normalizeComparableName($name);
        $normalizedValues = array_map(fn (string $value): string => $this->normalizeComparableName($value), $values);

        if (in_array($normalizedName, ['region sklepu i jezyk', 'waluta', 'currency', 'locale', 'jezyk'], true)) {
            return true;
        }

        foreach ($normalizedValues as $value) {
            if (str_contains($value, 'pln') || str_contains($value, 'zloty polski') || str_contains($value, 'polska polski')) {
                return true;
            }
        }

        return false;
    }

    private function labelForField(DOMXPath $xpath, DOMElement $field): ?string
    {
        $id = $field->getAttribute('id');

        if ($id !== '') {
            $labels = $xpath->query('//label[@for="'.$id.'"]');

            if ($labels !== false && $labels->length > 0) {
                $label = $labels->item(0);

                if ($label instanceof DOMElement) {
                    $text = $this->normalizeLabel($label->textContent ?? '');

                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        $previous = $field->previousSibling;

        while ($previous instanceof DOMNode) {
            if ($previous instanceof DOMElement && mb_strtolower($previous->tagName) === 'label') {
                $text = $this->normalizeLabel($previous->textContent ?? '');

                if ($text !== '') {
                    return $text;
                }
            }

            $previous = $previous->previousSibling;
        }

        return null;
    }

    private function containsMedicalDeviceNotice(string $html): bool
    {
        $text = $this->normalizeComparableName($this->htmlToText($html));

        return preg_match('/\bwyrob[a-z]*\s+medyczn[a-z]*\b/u', $text) === 1
            || str_contains($text, 'medical device');
    }

    private function firstElement(DOMXPath $xpath, string $query): ?DOMElement
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function firstElementFromContext(DOMXPath $xpath, string $query, DOMElement $context): ?DOMElement
    {
        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $childNode) {
            $html .= $element->ownerDocument?->saveHTML($childNode) ?? '';
        }

        return $html;
    }

    private function cleanHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+(data-[a-z0-9_-]+|wire:[a-z0-9_.-]+)="[^"]*"/iu', '', $html) ?? $html;

        return trim($html);
    }

    private function htmlToText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return $this->normalizeLabel(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function parseMoney(string $value): ?float
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[^0-9,.-]/u', '', str_replace("\xc2\xa0", ' ', $value)) ?? '';
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->absoluteUrl($url, $baseUrl ?? 'https://'.self::TWOJAPERUKA_HOST.'/');

        if ($absolute === null) {
            return null;
        }

        $host = parse_url($absolute, PHP_URL_HOST);

        if ($host === null || mb_strtolower($host) !== self::TWOJAPERUKA_HOST) {
            return null;
        }

        $path = (string) parse_url($absolute, PHP_URL_PATH);

        if ($path === '' || $path === '/' || str_starts_with($path, '/pl/c/') || str_starts_with($path, '/pl/n/')) {
            return null;
        }

        return $this->withoutQueryAndFragment($absolute);
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $absolute = $this->absoluteUrl($url, $baseUrl);

        if ($absolute === null) {
            return null;
        }

        $host = parse_url($absolute, PHP_URL_HOST);

        if ($host === null || mb_strtolower($host) !== self::TWOJAPERUKA_HOST) {
            return null;
        }

        return $this->withoutQueryAndFragment($absolute);
    }

    private function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($baseUrl, PHP_URL_HOST) ?: self::TWOJAPERUKA_HOST;

            return $scheme.'://'.$host.$url;
        }

        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
        $baseDirectory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        if ($baseDirectory === '' || $baseDirectory === '.') {
            $baseDirectory = '';
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: self::TWOJAPERUKA_HOST;

        return $scheme.'://'.$host.$baseDirectory.'/'.$url;
    }

    private function withoutQueryAndFragment(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST) ?: self::TWOJAPERUKA_HOST;
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        return $scheme.'://'.$host.$path;
    }

    private function slugFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $slug = basename($path);

        return $slug !== '' ? $slug : md5($url);
    }

    private function isHomeBreadcrumb(string $name): bool
    {
        $normalized = $this->normalizeComparableName($name);

        if ($normalized === 'strona glowna' || $normalized === 'przejdz do strona glowna') {
            return true;
        }

        $compact = str_replace(' ', '', $normalized);

        return in_array($compact, [
            'twojaperukapl',
            'wwwtwojaperukapl',
        ], true);
    }

    private function normalizeLabel(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeComparableName(string $name): string
    {
        $name = mb_strtolower($this->normalizeLabel($name));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        if (is_string($transliterated) && $transliterated !== '') {
            $name = $transliterated;
        }

        return preg_replace('/[^a-z0-9]+/', ' ', $name) ? trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name)) : $name;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopTwojaPerukaScraper/1.0)',
        ];
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
