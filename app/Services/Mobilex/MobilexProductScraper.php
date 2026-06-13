<?php

declare(strict_types=1);

namespace App\Services\Mobilex;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MobilexProductScraper
{
    private const MOBILEX_HOST = 'mobilex.pl';
    private const GALERIA_ZDROWIA_HOST = 'galeriazdrowia.pl';

    /**
     * Product pages can expose useful taxonomy classes on the main post wrapper.
     * Keep this whitelist conservative until we confirm more Mobilex product families.
     *
     * @var array<string, string>
     */
    private const ATTRIBUTE_PREFIX_LABELS = [
        'producent' => 'Producent',
        'material_ramy' => 'Materiał ramy',
        'dla_kogo' => 'Dla kogo',
        'rodzaj_wozka' => 'Rodzaj wózka',
        'max_obciazenie' => 'Max obciążenie',
        'kod_refundacji' => 'Kod refundacji',
        'skladanie_wozka' => 'Składanie wózka',
    ];

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
     * @param  array<string, mixed>|null  $categoryContext
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $categoryContext = null): array
    {
        $normalizedUrl = $this->normalizeProductUrl($url)
            ?? $this->normalizeGaleriaZdrowiaProductUrl($url)
            ?? $url;

        if ($this->isGaleriaZdrowiaUrl($normalizedUrl)) {
            $this->emit('Fetching Galeria Zdrowia product page: '.$normalizedUrl);

            return $this->extract($this->fetchBody($normalizedUrl), $normalizedUrl, $categoryContext);
        }

        $this->emit('Fetching Mobilex product page: '.$normalizedUrl);
        $mobilexHtml = $this->fetchBody($normalizedUrl);
        $galeriaUrl = $this->extractGaleriaZdrowiaProductUrl($mobilexHtml, $normalizedUrl);

        if ($galeriaUrl === null) {
            throw new \RuntimeException('Mobilex product page does not contain acf-link_do_produktu Galeria Zdrowia product link: '.$normalizedUrl);
        }

        $this->emit('Resolved Galeria Zdrowia product page: '.$galeriaUrl);
        $this->emit('Fetching Galeria Zdrowia product page: '.$galeriaUrl);

        $galeriaHtml = $this->fetchBody($galeriaUrl);
        $context = is_array($categoryContext) ? $categoryContext : [];
        $context['mobilex_product_url'] = $normalizedUrl;

        return $this->extract($galeriaHtml, $galeriaUrl, $context);
    }

    /**
     * @param  array<string, mixed>|null  $categoryContext
     * @return array<string, mixed>
     */
    public function extract(string $html, string $url, ?array $categoryContext = null): array
    {
        $crawler = new Crawler($html, $url);

        if ($this->isGaleriaZdrowiaUrl($url) || $crawler->filter('h1.product_title, form.variations_form')->count() > 0) {
            return $this->extractGaleriaZdrowiaProduct($html, $url, $categoryContext);
        }

        $canonicalUrl = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href');
        $canonicalUrl = is_string($canonicalUrl) ? ($this->normalizeProductUrl($canonicalUrl, $url) ?? $canonicalUrl) : null;
        $sourceUrl = $canonicalUrl ?: ($this->normalizeProductUrl($url) ?? $url);

        $seoTitle = $this->normalizeLabel($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');

        $tabs = $this->extractTabs($crawler);
        $descriptionHtml = $this->tabHtmlFromTabs($tabs, 'opis produktu');
        $specificationHtml = $this->tabHtmlFromTabs($tabs, 'specyfikacja');
        $medicalInfoHtml = $this->extractMedicalInfoHtml($crawler);
        $categoryFromContext = $this->categoryFromContext($categoryContext);
        $category = $categoryFromContext ?? $this->extractCategoryFromBreadcrumbs($crawler);
        $name = $this->extractName($crawler, $seoTitle);
        $warnings = $this->categoryWarnings($categoryFromContext, $category);

        $attributes = $this->extractAttributes($crawler, $descriptionHtml, $specificationHtml);
        $brand = $this->extractBrand($crawler, $category, $attributes, $medicalInfoHtml, $name);

        return [
            'source' => 'mobilex',
            'external_product_id' => $this->extractExternalProductId($crawler, $html),
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => $name,
            'brand' => $brand,
            'category' => $category,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'short_description' => $seoDescription,
            'images' => $this->extractImages($crawler, $url),
            'description_html' => $descriptionHtml,
            'specification_html' => $specificationHtml,
            'tabs' => $tabs,
            'documents' => $this->extractDocuments($crawler, $url),
            'attributes' => $attributes,
            'variant_candidates' => $this->extractVariantCandidates($specificationHtml),
            'medical_info_html' => $medicalInfoHtml,
            'warnings' => $warnings,
        ];
    }


    private function extractGaleriaZdrowiaProductUrl(string $html, string $baseUrl): ?string
    {
        try {
            $crawler = new Crawler($html, $baseUrl);

            foreach (['.acf-wrap.acf-link_do_produktu a[href]', '.acf-link_do_produktu a[href]'] as $selector) {
                $link = $crawler->filter($selector)->first();

                if ($link->count() === 0) {
                    continue;
                }

                $url = $this->normalizeGaleriaZdrowiaProductUrl((string) $link->attr('href'), $baseUrl);

                if ($url !== null) {
                    return $url;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $categoryContext
     * @return array<string, mixed>
     */
    private function extractGaleriaZdrowiaProduct(string $html, string $url, ?array $categoryContext = null): array
    {
        $crawler = new Crawler($html, $url);
        $canonicalUrl = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href');
        $canonicalUrl = is_string($canonicalUrl) ? ($this->normalizeGaleriaZdrowiaProductUrl($canonicalUrl, $url) ?? $canonicalUrl) : null;
        $sourceUrl = $canonicalUrl ?: ($this->normalizeGaleriaZdrowiaProductUrl($url) ?? $url);

        $seoTitle = $this->normalizeLabel($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $descriptionHtml = $this->extractGaleriaDescriptionHtml($crawler);
        $specificationHtml = $this->extractGaleriaSpecificationHtml($descriptionHtml);
        $medicalInfoHtml = $this->extractGaleriaMedicalInfoHtml($crawler);
        $categoryFromContext = $this->categoryFromContext($categoryContext);
        $category = $categoryFromContext ?? $this->extractGaleriaCategoryFromProductMeta($crawler);
        $name = $this->extractGaleriaName($crawler, $seoTitle);
        $attributes = $this->extractGaleriaAttributes($name, $descriptionHtml, $medicalInfoHtml);
        $brand = $this->extractGaleriaBrand($name, $attributes, $medicalInfoHtml);
        $variantCandidates = $this->extractGaleriaVariationCandidates($crawler);
        $priceGrossAmount = $this->extractGaleriaProductPriceGrossAmount($crawler);

        if ($variantCandidates === [] && $specificationHtml !== null) {
            $variantCandidates = $this->extractVariantCandidates($specificationHtml);
        }

        $tabs = [];

        if ($descriptionHtml !== null) {
            $tabs['opis_produktu'] = $descriptionHtml;
        }

        if ($specificationHtml !== null) {
            $tabs['specyfikacja'] = $specificationHtml;
        }

        $mobilexSourceUrl = is_array($categoryContext ?? null)
            ? $this->normalizeProductUrl((string) ($categoryContext['mobilex_product_url'] ?? ''))
            : null;

        return [
            'source' => 'mobilex',
            'external_product_id' => $this->extractExternalProductId($crawler, $html),
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'mobilex_source_url' => $mobilexSourceUrl,
            'slug' => $this->slugFromUrl($sourceUrl),
            'name' => $name,
            'brand' => $brand,
            'category' => $category,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'short_description' => $seoDescription,
            'price_gross_amount' => $priceGrossAmount,
            'currency' => 'PLN',
            'images' => $this->extractGaleriaImages($crawler, $url),
            'description_html' => $descriptionHtml,
            'specification_html' => $specificationHtml,
            'tabs' => $tabs,
            'documents' => $this->extractDocuments($crawler, $url),
            'attributes' => $attributes,
            'variant_candidates' => $variantCandidates,
            'medical_info_html' => $medicalInfoHtml,
            'warnings' => $this->categoryWarnings($categoryFromContext, $category),
        ];
    }

    private function extractGaleriaName(Crawler $crawler, string $seoTitle): ?string
    {
        foreach (['h1.product_title', 'h1.entry-title', '.product_title', 'h1'] as $selector) {
            $name = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($name !== '') {
                return $name;
            }
        }

        if ($seoTitle !== '') {
            return preg_replace('/\s+-\s+GaleriaZdrowia\.pl$/u', '', $seoTitle) ?: $seoTitle;
        }

        return null;
    }

    /**
     * @return array{name: string|null, url: string|null, logo_url: string|null}
     */
    private function extractGaleriaBrand(?string $name, array $attributes, ?string $medicalInfoHtml): array
    {
        $producer = $this->firstAttributeValue($attributes, 'Producent');

        if ($producer === null && $medicalInfoHtml !== null) {
            $producer = $this->extractProducerNameFromMedicalInfo($medicalInfoHtml);
        }

        if ($producer === null) {
            $producer = $this->inferProducerFromText($name ?? '');
        }

        return [
            'name' => $producer,
            'url' => null,
            'logo_url' => null,
        ];
    }

    /**
     * @return array<int, array{url: string, alt: string|null, source: string}>
     */
    private function extractGaleriaImages(Crawler $crawler, string $baseUrl): array
    {
        $images = [];

        $this->collectImagesFromNodes($crawler, '.woocommerce-product-gallery__image a[href], .woocommerce-product-gallery a[href]', 'href', $baseUrl, 'gallery', $images);
        $this->collectImagesFromNodes($crawler, '.woocommerce-product-gallery img[data-large_image]', 'data-large_image', $baseUrl, 'gallery', $images);
        $this->collectImagesFromNodes($crawler, '.woocommerce-product-gallery img[src]', 'src', $baseUrl, 'gallery', $images);

        $ogImage = $this->firstMetaPropertyContent($crawler, 'og:image');

        if ($ogImage !== null) {
            $normalizedOgImage = $this->normalizeUrl($ogImage, $baseUrl);

            if ($normalizedOgImage !== null && $this->isImageUrl($normalizedOgImage) && ! isset($images[$normalizedOgImage])) {
                $images[$normalizedOgImage] = [
                    'url' => $normalizedOgImage,
                    'alt' => $this->extractGaleriaName($crawler, '') ?? null,
                    'source' => 'og:image',
                ];
            }
        }

        return $this->normalizeImages($images);
    }

    private function extractGaleriaDescriptionHtml(Crawler $crawler): ?string
    {
        foreach ([
            '.nasa-content-description .nasa-content-panel',
            '#nasa-scroll-description .nasa-content-panel',
            '.woocommerce-tabs .nasa-content-panel',
            '.woocommerce-product-details__short-description',
            '.entry-summary .woocommerce-product-details__short-description',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() === 0) {
                    continue;
                }

                $html = $this->cleanHtml($node->html(''));

                if ($html !== '') {
                    return $html;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function extractGaleriaSpecificationHtml(?string $descriptionHtml): ?string
    {
        if ($descriptionHtml === null) {
            return null;
        }

        try {
            $crawler = new Crawler('<div>'.$descriptionHtml.'</div>');
            $table = $crawler->filter('table')->first();

            if ($table->count() === 0) {
                return null;
            }

            return $this->cleanHtml($table->outerHtml());
        } catch (Throwable) {
            return null;
        }
    }

    private function extractGaleriaMedicalInfoHtml(Crawler $crawler): ?string
    {
        try {
            foreach (['.product_meta + .row div', '.row .large-12 div', 'div'] as $selector) {
                $matched = null;

                $crawler->filter($selector)->each(function (Crawler $node) use (&$matched): void {
                    if ($matched !== null) {
                        return;
                    }

                    $text = $this->normalizeLabel($node->text(''));

                    if ($this->textContains($text, 'Produkt jest wyrobem medycznym')
                        && ($this->textContains($text, 'Producentem') || $this->textContains($text, 'dystrybutorem'))) {
                        $matched = $this->cleanHtml($node->html(''));
                    }
                });

                if (is_string($matched) && $matched !== '') {
                    return $matched;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function extractGaleriaAttributes(?string $name, ?string $descriptionHtml, ?string $medicalInfoHtml): array
    {
        $producer = $medicalInfoHtml !== null ? $this->extractProducerNameFromMedicalInfo($medicalInfoHtml) : null;

        if ($producer === null) {
            $producer = $this->inferProducerFromText(implode(' ', array_filter([$name, $descriptionHtml])));
        }

        if ($producer === null) {
            return [];
        }

        return [
            $this->attributePayload('producent', 'Producent', $producer),
        ];
    }

    private function inferProducerFromText(string $text): ?string
    {
        $text = $this->normalizeLabel($text);

        foreach (['Scholl', 'Levabo', 'Patron', 'Ormesa', 'Orthos XXI', 'Offcarr', 'Mobilex'] as $producer) {
            if ($this->textContains($text, $producer)) {
                return $producer;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{external_variant_id: string|null, sku: string|null, label: string, attributes: array<int, array{label: string, value: string}>, price_gross_amount?: int|null, regular_price_gross_amount?: int|null, currency?: string|null}>
     */
    private function extractGaleriaVariationCandidates(Crawler $crawler): array
    {
        $form = $crawler->filter('form.variations_form')->first();

        if ($form->count() === 0) {
            return [];
        }

        $optionLabels = $this->galeriaVariationOptionLabels($form);
        $attributeLabels = $this->galeriaVariationAttributeLabels($form);
        $selectCandidates = $this->galeriaVariationCandidatesFromSelectOptions($form, $attributeLabels);
        $pixelVariationData = $this->galeriaPixelYourSiteVariationData($crawler);
        $decodedCandidates = [];

        $raw = $form->attr('data-product_variations');
        $decoded = [];

        if (is_string($raw) && trim($raw) !== '') {
            $decodedValue = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            $decoded = is_array($decodedValue) ? $decodedValue : [];
        }

        foreach ($decoded as $index => $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $variationAttributes = is_array($variation['attributes'] ?? null) ? $variation['attributes'] : [];
            $attributes = [];
            $labelParts = [];

            foreach ($variationAttributes as $attributeName => $rawValue) {
                if (! is_string($attributeName) || ! is_string($rawValue) || trim($rawValue) === '') {
                    continue;
                }

                $value = $optionLabels[$attributeName][$rawValue] ?? $this->attributeLabelTitleCase(str_replace('-', ' ', $rawValue));
                $label = $attributeLabels[$attributeName] ?? $this->attributeLabelFromWooCommerceName($attributeName);

                $attributes[] = [
                    'label' => $label,
                    'value' => $value,
                ];
                $labelParts[] = $value;
            }

            if ($attributes === []) {
                continue;
            }

            $variationId = $this->stringOrNull($variation['variation_id'] ?? null);
            $sku = $this->stringOrNull($variation['sku'] ?? null);
            $label = implode(' / ', $labelParts);
            $candidate = [
                'external_variant_id' => $variationId ?: $sku ?: 'variation-'.($index + 1),
                'sku' => $sku,
                'label' => $label !== '' ? $label : 'Wariant '.($index + 1),
                'attributes' => $attributes,
                'price_gross_amount' => $this->grossMinorAmountFromWooCommercePrice($variation['display_price'] ?? null),
                'regular_price_gross_amount' => $this->grossMinorAmountFromWooCommercePrice($variation['display_regular_price'] ?? null),
                'currency' => 'PLN',
            ];

            $lookupData = $pixelVariationData[$this->variationLookupKey((string) $candidate['label'])] ?? null;

            if (is_array($lookupData)) {
                $candidate = $this->mergeGaleriaVariationData($candidate, $lookupData);
            }

            $decodedCandidates[$this->galeriaVariationKey($variationAttributes)] = $candidate;
        }

        if ($decodedCandidates !== []) {
            if ($selectCandidates === []) {
                return array_values($decodedCandidates);
            }

            $ordered = [];
            $usedKeys = [];

            foreach ($selectCandidates as $candidate) {
                $key = $this->stringOrNull($candidate['_key'] ?? null);

                if ($key !== null && array_key_exists($key, $decodedCandidates)) {
                    $ordered[] = $decodedCandidates[$key];
                    $usedKeys[] = $key;
                }
            }

            foreach ($decodedCandidates as $key => $candidate) {
                if (! in_array($key, $usedKeys, true)) {
                    $ordered[] = $candidate;
                }
            }

            return $ordered;
        }

        $ordered = [];

        foreach ($selectCandidates as $candidate) {
            unset($candidate['_key']);

            $lookupData = $pixelVariationData[$this->variationLookupKey((string) ($candidate['label'] ?? ''))] ?? null;

            if (is_array($lookupData)) {
                $candidate = $this->mergeGaleriaVariationData($candidate, $lookupData);
            }

            $ordered[] = $candidate;
        }

        return $ordered;
    }

    /**
     * @param  array<string, string>  $attributeLabels
     * @return array<int, array{_key: string, external_variant_id: string|null, sku: string|null, label: string, attributes: array<int, array{label: string, value: string}>, price_gross_amount: int|null, regular_price_gross_amount: int|null, currency: string}>
     */
    private function galeriaVariationCandidatesFromSelectOptions(Crawler $form, array $attributeLabels): array
    {
        $selects = $form->filter('select[name]');

        // Galeria Zdrowia currently exposes Mobilex product variants through one WooCommerce
        // select, usually pa_typ. For multi-attribute products, data-product_variations is
        // still the safer source because it contains valid combinations.
        if ($selects->count() !== 1) {
            return [];
        }

        $select = $selects->first();
        $attributeName = $select->attr('name');

        if (! is_string($attributeName) || $attributeName === '') {
            return [];
        }

        $attributeLabel = $attributeLabels[$attributeName] ?? $this->attributeLabelFromWooCommerceName($attributeName);
        $candidates = [];

        $select->filter('option[value]')->each(function (Crawler $option) use (&$candidates, $attributeName, $attributeLabel): void {
            $value = $option->attr('value');
            $label = $this->normalizeLabel($option->text(''));

            if (! is_string($value) || trim($value) === '' || $label === '') {
                return;
            }

            $candidates[] = [
                '_key' => $this->galeriaVariationKey([$attributeName => $value]),
                'external_variant_id' => $value,
                'sku' => null,
                'label' => $label,
                'attributes' => [
                    [
                        'label' => $attributeLabel,
                        'value' => $label,
                    ],
                ],
                'price_gross_amount' => null,
                'regular_price_gross_amount' => null,
                'currency' => 'PLN',
            ];
        });

        return $candidates;
    }

    /**
     * @return array<string, array{external_variant_id: string|null, price_gross_amount: int|null, regular_price_gross_amount: int|null, currency: string|null}>
     */
    private function galeriaPixelYourSiteVariationData(Crawler $crawler): array
    {
        $data = [];

        try {
            $crawler->filter('script')->each(function (Crawler $script) use (&$data): void {
                $text = $script->text('', false);

                if (! is_string($text) || ! str_contains($text, 'window.pysWooProductData')) {
                    return;
                }

                preg_match_all('/window\.pysWooProductData\[\s*(\d+)\s*\]\s*=\s*(\{.*?\})\s*;/su', $text, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $variationId = $match[1] ?? null;
                    $json = html_entity_decode($match[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $decoded = json_decode($json, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    $params = $decoded['facebook']['params'] ?? null;

                    if (! is_array($params)) {
                        continue;
                    }

                    $contentName = $this->stringOrNull($params['content_name'] ?? null);
                    $grossAmount = $this->grossMinorAmountFromWooCommercePrice($params['value'] ?? null);

                    if ($contentName === null || $grossAmount === null) {
                        continue;
                    }

                    $labels = [$contentName];

                    if (preg_match('/\s+-\s+(.+)$/u', $contentName, $labelMatch) === 1) {
                        $labels[] = $this->normalizeLabel($labelMatch[1]);
                    }

                    foreach ($labels as $label) {
                        $key = $this->variationLookupKey($label);

                        if ($key === '') {
                            continue;
                        }

                        $data[$key] ??= [
                            'external_variant_id' => $this->stringOrNull($variationId),
                            'price_gross_amount' => $grossAmount,
                            'regular_price_gross_amount' => $grossAmount,
                            'currency' => $this->stringOrNull($params['currency'] ?? null) ?? 'PLN',
                        ];
                    }
                }
            });
        } catch (Throwable) {
            return $data;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array{external_variant_id: string|null, price_gross_amount: int|null, regular_price_gross_amount: int|null, currency: string|null}  $data
     * @return array<string, mixed>
     */
    private function mergeGaleriaVariationData(array $candidate, array $data): array
    {
        $existingExternalId = $this->stringOrNull($candidate['external_variant_id'] ?? null);
        $incomingExternalId = $this->stringOrNull($data['external_variant_id'] ?? null);

        if ($incomingExternalId !== null && (
            $existingExternalId === null
            || str_starts_with($existingExternalId, 'variation-')
            || (! ctype_digit($existingExternalId) && ctype_digit($incomingExternalId))
        )) {
            $candidate['external_variant_id'] = $incomingExternalId;
        }

        $candidate['price_gross_amount'] ??= $data['price_gross_amount'] ?? null;
        $candidate['regular_price_gross_amount'] ??= $data['regular_price_gross_amount'] ?? $candidate['price_gross_amount'] ?? null;
        $candidate['currency'] = $data['currency'] ?? $candidate['currency'] ?? 'PLN';

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function galeriaVariationKey(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $attributeName => $rawValue) {
            if (! is_string($attributeName) || ! is_string($rawValue) || trim($rawValue) === '') {
                continue;
            }

            $parts[$attributeName] = trim($rawValue);
        }

        ksort($parts);

        return implode('|', array_map(
            fn (string $attributeName, string $rawValue): string => $attributeName.'='.$rawValue,
            array_keys($parts),
            $parts,
        ));
    }

    private function variationLookupKey(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = $this->normalizeLabel($value);

        return mb_strtolower($value, 'UTF-8');
    }

    private function extractGaleriaProductPriceGrossAmount(Crawler $crawler): ?int
    {
        $priceNode = $crawler->filter('.summary .price .woocommerce-Price-amount, p.price .woocommerce-Price-amount')->first();

        if ($priceNode->count() === 0) {
            return null;
        }

        return $this->grossMinorAmountFromWooCommercePrice($priceNode->text(''));
    }

    private function grossMinorAmountFromWooCommercePrice(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round(((float) $value) * 100);
        }

        if (! is_string($value) && ! $value instanceof \Stringable) {
            return null;
        }

        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[^0-9,.-]+/u', '', $value) ?? '';
        $value = str_replace(' ', '', trim($value));

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

        return (int) round(((float) $value) * 100);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function galeriaVariationOptionLabels(Crawler $form): array
    {
        $labels = [];

        try {
            $form->filter('select[name]')->each(function (Crawler $select) use (&$labels): void {
                $name = $select->attr('name');

                if (! is_string($name) || $name === '') {
                    return;
                }

                $select->filter('option[value]')->each(function (Crawler $option) use (&$labels, $name): void {
                    $value = $option->attr('value');
                    $text = $this->normalizeLabel($option->text(''));

                    if (! is_string($value) || trim($value) === '' || $text === '') {
                        return;
                    }

                    $labels[$name][$value] = $text;
                });
            });
        } catch (Throwable) {
            return $labels;
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function galeriaVariationAttributeLabels(Crawler $form): array
    {
        $labels = [];

        try {
            $form->filter('select[name]')->each(function (Crawler $select) use (&$labels): void {
                $name = $select->attr('name');

                if (! is_string($name) || $name === '') {
                    return;
                }

                $id = $select->attr('id');
                $label = null;

                if (is_string($id) && $id !== '') {
                    $labelNode = $form->filter('label[for="'.$id.'"]')->first();
                    $label = $labelNode->count() > 0 ? $this->normalizeNullableLabel($labelNode->text('')) : null;
                }

                $labels[$name] = $label ?? $this->attributeLabelFromWooCommerceName($name);
            });
        } catch (Throwable) {
            return $labels;
        }

        return $labels;
    }

    private function attributeLabelFromWooCommerceName(string $name): string
    {
        $name = preg_replace('/^attribute_(?:pa_)?/i', '', $name) ?: $name;
        $name = str_replace(['-', '_'], ' ', $name);

        return $this->attributeLabelTitleCase($name);
    }

    /**
     * @return array{top_name: string|null, top_url: string|null, name: string|null, url: string|null}|null
     */
    private function extractGaleriaCategoryFromProductMeta(Crawler $crawler): ?array
    {
        $categories = [];

        try {
            $crawler->filter('.posted_in a[href*="/kategoria-produktu/"]')->each(function (Crawler $node) use (&$categories): void {
                $href = $node->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeUrl($href);

                if ($url === null) {
                    return;
                }

                $categories[] = [
                    'name' => $this->normalizeLabel($node->text('')),
                    'url' => $url,
                ];
            });
        } catch (Throwable) {
            return null;
        }

        if ($categories === []) {
            return null;
        }

        $first = $categories[0];
        $last = $categories[array_key_last($categories)];

        return [
            'top_name' => $first['name'] !== '' ? $first['name'] : null,
            'top_url' => $first['url'],
            'name' => $last['name'] !== '' ? $last['name'] : null,
            'url' => $last['url'],
        ];
    }

    private function fetchBody(string $url): string
    {
        $this->pauseBeforeRequest();

        $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
            ->timeout($this->timeoutSeconds)
            ->withHeaders($this->headers())
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Mobilex product request failed with HTTP '.$response->status().' for '.$url);
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

    private function extractExternalProductId(Crawler $crawler, string $html): ?string
    {
        $apiHref = $this->firstAttr($crawler, 'link[href*="/wp-json/wp/v2/produkty/"], link[href*="/wp-json/wp/v2/product/"]', 'href');

        if (is_string($apiHref) && preg_match('#/wp-json/wp/v2/(?:produkty|product)/(\d+)#', $apiHref, $matches) === 1) {
            return $matches[1];
        }

        $shortlink = $this->firstAttr($crawler, 'link[rel="shortlink"][href]', 'href');

        if (is_string($shortlink) && preg_match('#[?&]p=(\d+)#', $shortlink, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\bpost-(\d+)\b/', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractName(Crawler $crawler, string $seoTitle): ?string
    {
        foreach (['h1.tbp_title', '.module-post-title h1', 'h1.entry-title', 'h1'] as $selector) {
            $name = $this->normalizeLabel($crawler->filter($selector)->first()->text(''));

            if ($name !== '') {
                return $name;
            }
        }

        if ($seoTitle !== '') {
            return preg_replace('/\s+-\s+Wózki inwalidzkie.+$/u', '', $seoTitle) ?: $seoTitle;
        }

        return null;
    }

    /**
     * @param  array{top_name: string|null, top_url: string|null, name: string|null, url: string|null}|null  $category
     * @param  array<int, array{code: string, label: string, value: string, slug: string}>  $attributes
     * @return array{name: string|null, url: string|null, logo_url: string|null}
     */
    private function extractBrand(Crawler $crawler, ?array $category, array $attributes, ?string $medicalInfoHtml, ?string $productName): array
    {
        $contextText = $this->normalizeLabel(implode(' ', array_filter([
            $productName,
            $category['top_name'] ?? null,
            $category['name'] ?? null,
            $medicalInfoHtml !== null ? $this->htmlToText($medicalInfoHtml) : null,
        ])));

        if ($this->textContains($contextText, 'scholl')) {
            return [
                'name' => 'Scholl',
                'url' => null,
                'logo_url' => null,
            ];
        }

        $fromProducerBlock = $this->extractBrandFromProducerBlock($crawler);
        $medicalProducer = $medicalInfoHtml !== null
            ? $this->extractProducerNameFromMedicalInfo($medicalInfoHtml)
            : null;
        $producerAttribute = $this->firstAttributeValue($attributes, 'Producent');
        $resolvedProducer = $this->canonicalBrandName($medicalProducer ?? $producerAttribute ?? $fromProducerBlock['name']);

        if ($resolvedProducer !== null) {
            return [
                'name' => $resolvedProducer,
                'url' => $fromProducerBlock['url'] ?? ($this->textContains($resolvedProducer, 'mobilex') ? 'https://mobilex.pl/producent/mobilex/' : null),
                'logo_url' => $fromProducerBlock['logo_url'] ?? null,
            ];
        }

        if ($this->textContains($contextText, 'mobilex')) {
            $logoUrl = $this->extractProducerLogoUrl($crawler);

            return [
                'name' => 'Mobilex',
                'url' => 'https://mobilex.pl/producent/mobilex/',
                'logo_url' => $logoUrl,
            ];
        }

        if ($fromProducerBlock['name'] !== null || $fromProducerBlock['url'] !== null || $fromProducerBlock['logo_url'] !== null) {
            return $fromProducerBlock;
        }

        return [
            'name' => null,
            'url' => null,
            'logo_url' => null,
        ];
    }

    /**
     * @return array{name: string|null, url: string|null, logo_url: string|null}
     */
    private function extractBrandFromProducerBlock(Crawler $crawler): array
    {
        $name = null;
        $url = null;
        $logoUrl = null;

        try {
            $link = $crawler->filter('.logo-producenta-link[href], .acf-logo_producenta a[href], .producent-logo a[href]')->first();

            if ($link->count() > 0) {
                $url = $this->normalizeUrl((string) $link->attr('href'));
                $name = $this->normalizeNullableLabel((string) $link->attr('title'));

                if ($name === null) {
                    $name = $this->normalizeNullableLabel($link->text(''));
                }

                $image = $link->filter('img')->first();

                if ($image->count() > 0) {
                    $rawLogoUrl = $this->firstNonEmptyString(
                        $image->attr('data-tf-src'),
                        $image->attr('data-src'),
                        $image->attr('src')
                    );
                    $logoUrl = $rawLogoUrl !== null ? $this->normalizeUrl($rawLogoUrl, $url) : null;

                    if ($name === null) {
                        $name = $this->brandNameFromLogo($rawLogoUrl ?? '', (string) ($image->attr('alt') ?? ''));
                    }
                }
            }
        } catch (Throwable) {
            // Keep brand nullable when the producer block has unexpected markup.
        }

        return [
            'name' => $name,
            'url' => $url,
            'logo_url' => $logoUrl,
        ];
    }

    private function extractProducerLogoUrl(Crawler $crawler): ?string
    {
        try {
            $image = $crawler->filter('.acf-logo_producenta img[src], .logo-producenta-link img[src], img[src*="logo_mobilex"]')->first();

            if ($image->count() === 0) {
                return null;
            }

            $rawLogoUrl = $this->firstNonEmptyString(
                $image->attr('data-tf-src'),
                $image->attr('data-src'),
                $image->attr('src')
            );

            return $rawLogoUrl !== null ? $this->normalizeUrl($rawLogoUrl) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function brandNameFromLogo(string $src, string $alt): ?string
    {
        $text = mb_strtolower($src.' '.$alt, 'UTF-8');

        if (str_contains($text, 'mobilex')) {
            return 'Mobilex';
        }

        if (str_contains($text, 'scholl')) {
            return 'Scholl';
        }

        if (str_contains($text, 'levabo')) {
            return 'Levabo';
        }

        $alt = $this->normalizeNullableLabel($alt);

        if ($alt !== null && ! $this->textContains($alt, 'logo producenta')) {
            return $alt;
        }

        return null;
    }

    private function extractProducerNameFromMedicalInfo(string $html): ?string
    {
        $text = $this->htmlToText($html);

        foreach ([
            '/Producentem\s+.+?\s+jest\s+firma\s+(.+?)(?:\s+kt[oó]rej|\s+którego|\s+ktory|\s+który|\s+Wyłącznym|\s+dystrybutorem|\s+jest\s+Firma|\.|$)/iu',
            '/producent(?:a|em)?\s+(?:jest\s+)?(.+?)(?:\s+kt[oó]rej|\s+którego|\s+Wyłącznym|\s+dystrybutorem|\.|$)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return $this->canonicalBrandName($matches[1]);
            }
        }

        return null;
    }

    private function canonicalBrandName(?string $name): ?string
    {
        $name = $name !== null ? $this->normalizeNullableLabel($name) : null;

        if ($name === null) {
            return null;
        }

        if ($this->textContains($name, 'scholl')) {
            return 'Scholl';
        }

        if ($this->textContains($name, 'levabo')) {
            return 'Levabo';
        }

        if ($this->textContains($name, 'patron')) {
            return 'Patron';
        }

        if ($this->textContains($name, 'ormesa')) {
            return 'Ormesa';
        }

        if ($this->textContains($name, 'orthos')) {
            return 'Orthos XXI';
        }

        if ($this->textContains($name, 'offcarr')) {
            return 'Offcarr';
        }

        if ($this->textContains($name, 'mobilex')) {
            return 'Mobilex';
        }

        return preg_replace('/\s+(?:A\/S|ApS|Sp\.\s*z\s*o\.o\.|a\.s\.|s\.r\.l\.|ltd\.?|lda\.?)\.?$/iu', '', $name) ?: $name;
    }

    /**
     * @return array<int, array{url: string, alt: string|null, source: string}>
     */
    private function extractImages(Crawler $crawler, string $baseUrl): array
    {
        $images = [];

        $this->collectImagesFromNodes($crawler, '.slick-gallery-container .slick-slider a[href], .slick-gallery-container a[href]', 'href', $baseUrl, 'gallery', $images);
        $this->collectImagesFromNodes($crawler, '.slick-gallery-container img[src]', 'src', $baseUrl, 'gallery', $images);

        $ogImage = $this->firstMetaPropertyContent($crawler, 'og:image');

        if ($ogImage !== null) {
            $normalizedOgImage = $this->normalizeUrl($ogImage, $baseUrl);

            if ($normalizedOgImage !== null && $this->isImageUrl($normalizedOgImage) && ! isset($images[$normalizedOgImage])) {
                $images[$normalizedOgImage] = [
                    'url' => $normalizedOgImage,
                    'alt' => $this->extractName($crawler, '') ?? null,
                    'source' => 'og:image',
                ];
            }
        }

        return $this->normalizeImages($images);
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, source: string}>  $images
     * @return array<int, array{url: string, alt: string|null, source: string}>
     */
    private function normalizeImages(array $images): array
    {
        if ($images === []) {
            return [];
        }

        $hasFullSizeOriginal = [];

        foreach (array_keys($images) as $url) {
            $hasFullSizeOriginal[$this->originalImageUrl($url) ?? $url] = true;
        }

        $filtered = [];

        foreach ($images as $url => $image) {
            $originalUrl = $this->originalImageUrl($url);

            if ($originalUrl !== null && $originalUrl !== $url && isset($hasFullSizeOriginal[$originalUrl])) {
                continue;
            }

            if ($originalUrl !== null && $originalUrl !== $url && count($images) > 1) {
                continue;
            }

            $filtered[$url] = $image;
        }

        return array_values($filtered);
    }

    private function originalImageUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }

        $path = (string) $parts['path'];
        $originalPath = preg_replace('/-(?:\d{2,5}x\d{2,5})(\.(?:jpe?g|png|webp|gif|avif))$/i', '$1', $path);

        if (! is_string($originalPath) || $originalPath === $path) {
            return $url;
        }

        return $parts['scheme'] . '://' . mb_strtolower((string) $parts['host']) . $originalPath;
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, source: string}>  $images
     */
    private function collectImagesFromNodes(Crawler $crawler, string $selector, string $attribute, string $baseUrl, string $source, array &$images): void
    {
        try {
            $crawler->filter($selector)->each(function (Crawler $node) use ($attribute, $baseUrl, $source, &$images): void {
                $rawUrl = $node->attr($attribute);

                if (! is_string($rawUrl)) {
                    return;
                }

                $url = $this->normalizeUrl($rawUrl, $baseUrl);

                if ($url === null || ! $this->isImageUrl($url)) {
                    return;
                }

                $alt = null;

                if ($node->getNode(0) instanceof \DOMElement && mb_strtolower($node->getNode(0)->nodeName) === 'img') {
                    $alt = $this->normalizeNullableLabel((string) $node->attr('alt'));
                } else {
                    $image = $node->filter('img[alt]')->first();

                    if ($image->count() > 0) {
                        $alt = $this->normalizeNullableLabel((string) $image->attr('alt'));
                    }
                }

                if (! isset($images[$url])) {
                    $images[$url] = [
                        'url' => $url,
                        'alt' => $alt,
                        'source' => $source,
                    ];

                    return;
                }

                if ($images[$url]['alt'] === null && $alt !== null) {
                    $images[$url]['alt'] = $alt;
                }
            });
        } catch (Throwable) {
            // Ignore malformed gallery markup and rely on later image fallbacks.
        }
    }

    /**
     * @return array<string, string>
     */
    private function extractTabs(Crawler $crawler): array
    {
        $tabs = [];

        try {
            $labels = [];

            $crawler->filter('.tabs > .tabs-nav label')->each(function (Crawler $node) use (&$labels): void {
                $labels[] = $this->normalizeLabel($node->text(''));
            });

            $crawler->filter('.tabs > .tabs-content > .tab')->each(function (Crawler $node, int $index) use (&$tabs, $labels): void {
                $label = $labels[$index] ?? 'Tab '.($index + 1);
                $key = $this->normalizeKey($label);
                $html = $this->cleanHtml($node->html(''));

                if ($key !== '' && $html !== '') {
                    $tabs[$key] = $html;
                }
            });
        } catch (Throwable) {
            return [];
        }

        return $tabs;
    }

    private function extractTabHtml(Crawler $crawler, string $label): ?string
    {
        return $this->tabHtmlFromTabs($this->extractTabs($crawler), $label);
    }

    /**
     * @param  array<string, string>  $tabs
     */
    private function tabHtmlFromTabs(array $tabs, string $label): ?string
    {
        $needle = $this->normalizeKey($label);

        foreach ($tabs as $key => $html) {
            if ($key === $needle || str_contains($key, $needle)) {
                return $html;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{label: string, url: string, extension: string|null}>
     */
    private function extractDocuments(Crawler $crawler, string $baseUrl): array
    {
        $documents = [];

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$documents, $baseUrl): void {
                $href = $node->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeUrl($href, $baseUrl);

                if ($url === null || ! $this->isDocumentUrl($url)) {
                    return;
                }

                $label = $this->normalizeLabel($node->text(''));

                if ($label === '') {
                    $label = basename((string) parse_url($url, PHP_URL_PATH));
                }

                if (isset($documents[$url])) {
                    return;
                }

                $documents[$url] = [
                    'label' => $label,
                    'url' => $url,
                    'extension' => $this->fileExtension($url),
                ];
            });
        } catch (Throwable) {
            return [];
        }

        return array_values($documents);
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function extractAttributes(Crawler $crawler, ?string $descriptionHtml, ?string $specificationHtml): array
    {
        return $this->filterIgnoredAttributes($this->mergeAttributes([
            $this->extractTaxonomyAttributes($crawler),
            $specificationHtml !== null ? $this->extractSpecificationTableAttributes($specificationHtml) : [],
            $descriptionHtml !== null ? $this->extractSpecificationListAttributes($descriptionHtml) : [],
        ]));
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function extractTaxonomyAttributes(Crawler $crawler): array
    {
        $classes = [];

        try {
            foreach (['body', 'main', 'article', '.type-produkty.produkty'] as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$classes): void {
                    $class = $node->attr('class');

                    if (is_string($class) && trim($class) !== '') {
                        $classes[] = $class;
                    }
                });
            }
        } catch (Throwable) {
            return [];
        }

        if ($classes === []) {
            return [];
        }

        $attributes = [];
        $tokens = preg_split('/\s+/', trim(implode(' ', $classes))) ?: [];

        foreach ($tokens as $token) {
            foreach (self::ATTRIBUTE_PREFIX_LABELS as $prefix => $label) {
                $expectedPrefix = $prefix.'-';

                if (! str_starts_with($token, $expectedPrefix)) {
                    continue;
                }

                $slug = substr($token, strlen($expectedPrefix));

                if ($slug === '' || $this->isIgnoredTaxonomyAttribute($prefix, $slug)) {
                    continue;
                }

                $attributes[$prefix.'|'.$slug] = $this->attributePayload(
                    $prefix,
                    $label,
                    $this->labelFromAttributeSlug($prefix, $slug),
                    $slug
                );
            }
        }

        return array_values($attributes);
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function extractSpecificationListAttributes(string $html): array
    {
        $attributes = [];

        try {
            $crawler = new Crawler('<div>'.$html.'</div>');
            $active = false;

            $crawler->filter('h2, h3, li')->each(function (Crawler $node) use (&$attributes, &$active): void {
                $tag = mb_strtolower($node->getNode(0)?->nodeName ?? '');
                $text = $this->normalizeLabel($node->text(''));

                if ($text === '') {
                    return;
                }

                if (in_array($tag, ['h2', 'h3'], true)) {
                    $key = $this->normalizeKey($text);
                    $active = str_contains($key, 'specyfikacja') || str_contains($key, 'dane_techniczne') || str_contains($key, 'parametry_techniczne');

                    return;
                }

                if (! $active || $tag !== 'li') {
                    return;
                }

                $pair = $this->attributePairFromText($text);

                if ($pair === null) {
                    return;
                }

                [$label, $value] = $pair;
                $attributes[] = $this->attributePayload($this->normalizeKey($label), $label, $value);
            });
        } catch (Throwable) {
            return [];
        }

        return $attributes;
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function extractSpecificationTableAttributes(string $html): array
    {
        $attributes = [];
        $table = $this->tableMatrix($html);

        if ($table === []) {
            return [];
        }

        foreach (array_slice($table, 1) as $row) {
            if (count($row) < 2) {
                continue;
            }

            $label = $this->normalizeLabel((string) ($row[0] ?? ''));
            $values = array_values(array_unique(array_filter(array_map(
                fn (string $value): string => $this->normalizeLabel($value),
                array_slice($row, 1)
            ))));

            if ($label === '' || $values === []) {
                continue;
            }

            $attributes[] = $this->attributePayload(
                $this->normalizeKey($label),
                $this->attributeLabelTitleCase($label),
                implode(' | ', $values)
            );
        }

        return $attributes;
    }

    /**
     * @return array<int, array{external_variant_id: string|null, sku: string|null, label: string, attributes: array<int, array{label: string, value: string}>}>
     */
    private function extractVariantCandidates(?string $specificationHtml): array
    {
        if ($specificationHtml === null) {
            return [];
        }

        $table = $this->tableMatrix($specificationHtml);

        if (count($table) < 2 || count($table[0]) < 2) {
            return [];
        }

        $headers = $table[0];
        $variants = [];

        foreach (array_slice($headers, 1) as $columnIndex => $header) {
            $label = $this->normalizeLabel($header);

            if ($label === '') {
                $label = $this->inferredVariantLabel($table, $columnIndex + 1);
            }

            if ($label === '') {
                continue;
            }

            $sku = null;

            if (preg_match('/(?:nr\s*art\.?|art\.?|sku)\s*[:.]?\s*([A-Z0-9._-]+)/iu', $label, $matches) === 1) {
                $sku = $matches[1];
            }

            $variantAttributes = [];
            $cellIndex = $columnIndex + 1;

            foreach (array_slice($table, 1) as $row) {
                $attributeLabel = $this->normalizeLabel((string) ($row[0] ?? ''));
                $value = $this->normalizeLabel((string) ($row[$cellIndex] ?? ''));

                if ($attributeLabel === '' || $value === '') {
                    continue;
                }

                $variantAttributes[] = [
                    'label' => $this->attributeLabelTitleCase($attributeLabel),
                    'value' => $value,
                ];
            }

            if ($variantAttributes === []) {
                continue;
            }

            $variants[] = [
                'external_variant_id' => $sku,
                'sku' => $sku,
                'label' => $label,
                'attributes' => $variantAttributes,
            ];
        }

        if (count($variants) === 1 && $variants[0]['sku'] === null) {
            return [];
        }

        return $variants;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function tableMatrix(string $html): array
    {
        try {
            $crawler = new Crawler('<div>'.$html.'</div>');
        } catch (Throwable) {
            return [];
        }

        $table = $crawler->filter('table')->first();

        if ($table->count() === 0) {
            return [];
        }

        $rows = [];

        try {
            $table->filter('tr')->each(function (Crawler $row) use (&$rows): void {
                $cells = [];

                $row->filter('th, td')->each(function (Crawler $cell) use (&$cells): void {
                    $cells[] = $this->normalizeLabel($cell->text(''));
                });

                if ($cells !== []) {
                    $rows[] = $cells;
                }
            });
        } catch (Throwable) {
            return [];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, string>>  $table
     */
    private function inferredVariantLabel(array $table, int $cellIndex): string
    {
        foreach (array_slice($table, 1) as $row) {
            $attributeLabel = $this->normalizeLabel((string) ($row[0] ?? ''));
            $value = $this->normalizeLabel((string) ($row[$cellIndex] ?? ''));

            if ($attributeLabel === '' || $value === '') {
                continue;
            }

            $rowValues = array_values(array_unique(array_filter(array_map(
                fn (string $cell): string => $this->normalizeLabel($cell),
                array_slice($row, 1)
            ))));

            if (count($rowValues) < 2) {
                continue;
            }

            return $this->attributeLabelTitleCase($attributeLabel).' '.$value;
        }

        return '';
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function attributePairFromText(string $text): ?array
    {
        if (preg_match('/^([^:：]{2,80})[:：]\s*(.+)$/u', $text, $matches) !== 1) {
            return null;
        }

        $label = $this->normalizeLabel($matches[1]);
        $value = $this->normalizeLabel($matches[2]);

        if ($label === '' || $value === '') {
            return null;
        }

        return [$this->attributeLabelTitleCase($label), $value];
    }

    /**
     * @param  array<int, array<int, array{code: string, label: string, value: string, slug: string}>>  $groups
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function mergeAttributes(array $groups): array
    {
        $attributes = [];

        foreach ($groups as $group) {
            foreach ($group as $attribute) {
                $key = $attribute['code'].'|'.$attribute['slug'].'|'.$attribute['value'];

                if (! isset($attributes[$key])) {
                    $attributes[$key] = $attribute;
                }
            }
        }

        return array_values($attributes);
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string}>  $attributes
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function filterIgnoredAttributes(array $attributes): array
    {
        return array_values(array_filter(
            $attributes,
            fn (array $attribute): bool => ! $this->isIgnoredAttributePayload($attribute)
        ));
    }

    /**
     * @param  array{code: string, label: string, value: string, slug: string}  $attribute
     */
    private function isIgnoredAttributePayload(array $attribute): bool
    {
        $code = $this->normalizeKey($attribute['code']);
        $slug = $this->normalizeKey($attribute['slug']);
        $value = $this->normalizeKey($attribute['value']);

        if ($code === 'producent' && in_array($slug, ['medical_info', 'logo_producenta'], true)) {
            return true;
        }

        if ($code === 'producent' && in_array($value, ['medical_info', 'medical_information', 'logo_producenta'], true)) {
            return true;
        }

        if ($code === 'producent' && (
            str_contains($value, 'sverigesvej')
            || str_contains($value, 'skanderborg')
            || str_contains($value, 'dania')
        )) {
            return true;
        }

        return false;
    }

    /**
     * @return array{code: string, label: string, value: string, slug: string}
     */
    private function attributePayload(string $code, string $label, string $value, ?string $slug = null): array
    {
        $label = $this->attributeLabelTitleCase($label);
        $value = $this->normalizeLabel($value);
        $slug = $slug !== null && trim($slug) !== '' ? trim($slug) : $this->normalizeKey($value);

        return [
            'code' => $this->normalizeKey($code),
            'label' => $label,
            'value' => $value,
            'slug' => $slug,
        ];
    }

    private function attributeLabelTitleCase(string $label): string
    {
        $label = $this->normalizeLabel($label);

        if ($label === '') {
            return $label;
        }

        if (mb_strlen($label, 'UTF-8') <= 4 && mb_strtoupper($label, 'UTF-8') === $label) {
            return $label;
        }

        return mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($label, 1, null, 'UTF-8');
    }

    /**
     * @param  array<int, array{code: string, label: string, value: string, slug: string}>  $attributes
     */
    private function firstAttributeValue(array $attributes, string $label): ?string
    {
        foreach ($attributes as $attribute) {
            if ($attribute['label'] === $label) {
                return $attribute['value'];
            }
        }

        return null;
    }

    private function isIgnoredTaxonomyAttribute(string $prefix, string $slug): bool
    {
        $normalizedSlug = $this->normalizeKey($slug);

        if ($normalizedSlug === '') {
            return true;
        }

        $ignoredByPrefix = [
            'producent' => [
                'medical_info',
                'medical-info',
                'logo_producenta',
                'logo-producenta',
            ],
        ];

        return in_array($normalizedSlug, $ignoredByPrefix[$prefix] ?? [], true);
    }

    private function labelFromAttributeSlug(string $prefix, string $slug): string
    {
        if ($prefix === 'kod_refundacji') {
            return mb_strtoupper(str_replace('-', '.', $slug), 'UTF-8');
        }

        if ($prefix === 'producent') {
            return mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
        }

        $value = str_replace('-', ' ', $slug);
        $value = preg_replace('/\bkg\b/u', 'kg', $value) ?: $value;

        return $this->normalizeLabel($value);
    }

    /**
     * @param  array{top_name: string|null, top_url: string|null, name: string|null, url: string|null}|null  $categoryFromContext
     * @param  array{top_name: string|null, top_url: string|null, name: string|null, url: string|null}|null  $resolvedCategory
     * @return array<int, string>
     */
    private function categoryWarnings(?array $categoryFromContext, ?array $resolvedCategory): array
    {
        if ($categoryFromContext !== null) {
            return [];
        }

        if ($resolvedCategory === null) {
            return ['Category could not be resolved. Pass --links=mobilex/product-links.json when possible.'];
        }

        return ['Category resolved from product breadcrumbs. Pass --links=mobilex/product-links.json to use scraper hierarchy context.'];
    }

    /**
     * @return array{top_name: string|null, top_url: string|null, name: string|null, url: string|null}|null
     */
    private function categoryFromContext(?array $context): ?array
    {
        if ($context === null) {
            return null;
        }

        $name = $this->normalizeNullableLabel((string) ($context['category_name'] ?? ''));
        $url = isset($context['category_url']) && is_string($context['category_url'])
            ? $this->normalizeUrl($context['category_url'])
            : null;
        $topName = $this->normalizeNullableLabel((string) ($context['top_category_name'] ?? ''));
        $topUrl = isset($context['top_category_url']) && is_string($context['top_category_url'])
            ? $this->normalizeUrl($context['top_category_url'])
            : null;

        if ($name === null && $url === null && $topName === null && $topUrl === null) {
            return null;
        }

        return [
            'top_name' => $topName,
            'top_url' => $topUrl,
            'name' => $name,
            'url' => $url,
        ];
    }

    /**
     * @return array{top_name: string|null, top_url: string|null, name: string|null, url: string|null}|null
     */
    private function extractCategoryFromBreadcrumbs(Crawler $crawler): ?array
    {
        $categories = [];

        try {
            $crawler->filter('#breadcrumbs a[href], .breadcrumbs a[href]')->each(function (Crawler $node) use (&$categories): void {
                $href = $node->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeUrl($href);

                if ($url === null || ! str_contains((string) parse_url($url, PHP_URL_PATH), '/kategoria-produktu/')) {
                    return;
                }

                $categories[] = [
                    'name' => $this->normalizeLabel($node->text('')),
                    'url' => $url,
                ];
            });
        } catch (Throwable) {
            return null;
        }

        if ($categories === []) {
            return null;
        }

        $first = $categories[0];
        $last = $categories[array_key_last($categories)];

        return [
            'top_name' => $first['name'] !== '' ? $first['name'] : null,
            'top_url' => $first['url'],
            'name' => $last['name'] !== '' ? $last['name'] : null,
            'url' => $last['url'],
        ];
    }

    private function extractMedicalInfoHtml(Crawler $crawler): ?string
    {
        try {
            $node = $crawler->filter('.producent-medical-info')->first();

            if ($node->count() === 0) {
                return null;
            }

            return $this->cleanHtml($node->html('')) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function htmlToText(string $html): string
    {
        return $this->normalizeLabel(strip_tags($html));
    }

    private function textContains(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack, 'UTF-8'), mb_strtolower($needle, 'UTF-8'));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        return $this->normalizeNullableLabel((string) $this->firstAttr($crawler, 'meta[name="'.$name.'"][content]', 'content'));
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        return $this->normalizeNullableLabel((string) $this->firstAttr($crawler, 'meta[property="'.$property.'"][content]', 'content'));
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                return null;
            }

            $value = $node->attr($attribute);

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|svg)\b[^>]*>.*?</\1>#is', '', $html) ?: $html;
        $html = preg_replace('/\s+on[a-z]+="[^"]*"/i', '', $html) ?: $html;
        $html = preg_replace("/\s+on[a-z]+='[^']*'/i", '', $html) ?: $html;
        $html = preg_replace('/\s+/u', ' ', $html) ?: $html;

        return trim($html);
    }

    private function normalizeGaleriaZdrowiaProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isGaleriaZdrowiaUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/produkt/[a-z0-9][a-z0-9\-/]*/$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::GALERIA_ZDROWIA_HOST.$path;
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isMobilexUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/produkty/[a-z0-9][a-z0-9\-/]*/$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::MOBILEX_HOST.$path;
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
            $base = $baseUrl !== null ? parse_url($baseUrl) : [];
            $host = isset($base['host']) ? mb_strtolower((string) $base['host']) : self::MOBILEX_HOST;
            $scheme = isset($base['scheme']) ? (string) $base['scheme'] : 'https';
            $url = $scheme.'://'.$host.$url;
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

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.mb_strtolower((string) $parts['host']).$this->normalizeGenericPath($path).$query;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function normalizeGenericPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        if (preg_match('#\.[a-z0-9]{2,5}$#i', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/').'/';
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function normalizeNullableLabel(string $label): ?string
    {
        $label = $this->normalizeLabel($label);

        return $label !== '' ? $label : null;
    }

    private function normalizeKey(string $label): string
    {
        $label = mb_strtolower($this->normalizeLabel($label), 'UTF-8');
        $label = strtr($label, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ]);
        $label = preg_replace('/[^a-z0-9]+/u', '_', $label) ?: $label;

        return trim($label, '_');
    }

    private function slugFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = (string) end($segments);

        return $slug !== '' ? $slug : null;
    }

    private function isImageUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(?:jpe?g|png|webp|gif|svg|avif|heic|heif)$/', $path) === 1;
    }

    private function isDocumentUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(?:pdf|docx?|xlsx?|zip)$/', $path) === 1;
    }

    private function fileExtension(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? mb_strtolower($extension) : null;
    }

    private function isMobilexUrl(string $url): bool
    {
        return mb_strtolower((string) parse_url($url, PHP_URL_HOST)) === self::MOBILEX_HOST;
    }

    private function isGaleriaZdrowiaUrl(string $url): bool
    {
        return mb_strtolower((string) parse_url($url, PHP_URL_HOST)) === self::GALERIA_ZDROWIA_HOST;
    }
}
