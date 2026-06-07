<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class WojdakProductPayloadExtractor
{
    private const WOJDAK_SHOP_HOST = 'sklep.wojdak.pl';
    private const LEGACY_WOJDAK_HOST = 'wojdak.pl';

    /**
     * @return array<string, mixed>
     */
    public function extract(string $html, ?string $sourceUrl = null): array
    {
        $crawler = new Crawler($html, $sourceUrl ?? 'https://sklep.wojdak.pl');
        $canonicalUrl = $this->normalizeUrl($this->extractCanonical($crawler)) ?? $this->normalizeUrl($sourceUrl);
        $categorySlug = $this->extractProductCategorySlug($crawler);
        $categoryUrl = $this->extractCategoryUrl($crawler, $categorySlug);
        $sizeTablePdfUrl = $this->extractSizeTablePdfUrl($crawler);
        $attributeDefinitions = $this->extractAttributeDefinitions($crawler);
        $woocommerceVariations = $this->extractWooCommerceVariations($crawler, $attributeDefinitions);
        $descriptionHtml = $this->extractHtml($crawler, '.product-description')
            ?? $this->extractHtml($crawler, '#tab-description')
            ?? $this->extractHtml($crawler, '.woocommerce-Tabs-panel--description')
            ?? $this->extractHtml($crawler, '.woocommerce-product-details__short-description')
            ?? $this->extractHtml($crawler, '.single-product__about-text');
        $descriptionText = $this->normalizeWhitespace(strip_tags(html_entity_decode((string) $descriptionHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $name = $this->extractFirstText($crawler, 'h1.product_title')
            ?? $this->extractFirstText($crawler, '.single-product__about-title')
            ?? $this->cleanTitle($this->extractJsonLdValue($crawler, 'name'))
            ?? $this->cleanTitle($this->extractMetaProperty($crawler, 'og:title'))
            ?? $this->cleanTitle($this->extractFirstText($crawler, 'title'));

        return [
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_id' => $this->externalIdFromUrl($canonicalUrl ?? $sourceUrl),
            'name' => $name,
            'title_tag' => $this->extractFirstText($crawler, 'title'),
            'meta_description' => $this->extractMetaByName($crawler, 'description'),
            'meta_og_description' => $this->extractMetaProperty($crawler, 'og:description'),
            'parent_sku' => $this->extractFirstText($crawler, '.product_meta .sku')
                ?? $this->extractFirstText($crawler, '.sku_wrapper .sku')
                ?? $this->extractJsonLdValue($crawler, 'sku'),
            'description_html' => $descriptionHtml,
            'description_text' => $descriptionText,
            'category_url' => $categoryUrl,
            'category_slug' => $categorySlug ?? $this->slugFromCategoryUrl($categoryUrl),
            'size_table_pdf_url' => $sizeTablePdfUrl,
            'size_table_type' => $this->sizeTableType($sizeTablePdfUrl, $categoryUrl, $categorySlug, $name, $descriptionText),
            'images' => $this->extractImages($crawler, $woocommerceVariations),
            'woocommerce_product_id' => $this->extractWooCommerceProductId($crawler),
            'woocommerce_variations' => $woocommerceVariations,
        ];
    }

    private function extractCanonical(Crawler $crawler): ?string
    {
        return $this->extractAttribute($crawler, 'link[rel="canonical"]', 'href');
    }

    private function extractMetaByName(Crawler $crawler, string $name): ?string
    {
        return $this->normalizeWhitespace($this->extractAttribute($crawler, sprintf('meta[name="%s"]', $name), 'content'));
    }

    private function extractMetaProperty(Crawler $crawler, string $property): ?string
    {
        return $this->normalizeWhitespace($this->extractAttribute($crawler, sprintf('meta[property="%s"]', $property), 'content'));
    }

    private function extractFirstText(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                return null;
            }

            return $this->normalizeWhitespace($node->first()->text());
        } catch (Throwable) {
            return null;
        }
    }

    private function extractHtml(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                return null;
            }

            $html = trim($node->first()->html());

            return $html === '' ? null : $html;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractAttribute(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                return null;
            }

            $value = $node->first()->attr($attribute);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractWooCommerceProductId(Crawler $crawler): ?string
    {
        return $this->extractAttribute($crawler, 'form.variations_form[data-product_id]', 'data-product_id')
            ?? $this->extractAttribute($crawler, 'input[name="product_id"]', 'value')
            ?? $this->extractAttribute($crawler, 'input[name="add-to-cart"]', 'value');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function extractAttributeDefinitions(Crawler $crawler): array
    {
        $definitions = [];

        try {
            $crawler->filter('select[name^="attribute_"], select[data-attribute_name]')->each(function (Crawler $node) use (&$definitions): void {
                $rawAttributeName = $node->attr('data-attribute_name') ?: $node->attr('name');

                if (! is_string($rawAttributeName) || trim($rawAttributeName) === '') {
                    return;
                }

                $rawAttributeName = trim($rawAttributeName);
                $code = $this->attributeCodeFromWooName($rawAttributeName);
                $options = [];

                $node->filter('option[value]')->each(function (Crawler $option) use (&$options): void {
                    $value = $option->attr('value');

                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    $options[$value] = $this->normalizeWhitespace($option->text()) ?? $value;
                });

                $definitions[$rawAttributeName] = [
                    'raw_name' => $rawAttributeName,
                    'code' => $code,
                    'name' => $this->attributeLabel($code),
                    'external_attribute_id' => 'wojdak-'.$code,
                    'options' => $options,
                ];
            });
        } catch (Throwable) {
            return $definitions;
        }

        return $definitions;
    }

    /**
     * @param  array<string, array<string, mixed>>  $attributeDefinitions
     * @return array<int, array<string, mixed>>
     */
    private function extractWooCommerceVariations(Crawler $crawler, array $attributeDefinitions): array
    {
        $json = $this->extractAttribute($crawler, 'form.variations_form[data-product_variations]', 'data-product_variations');

        if (! is_string($json) || trim($json) === '' || trim($json) === 'false') {
            return [];
        }

        $decoded = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

        if (! is_array($decoded)) {
            return [];
        }

        $variations = [];

        foreach ($decoded as $index => $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $attributes = [];

            foreach (($variation['attributes'] ?? []) as $rawAttributeName => $rawValue) {
                if (! is_string($rawAttributeName) || ! is_scalar($rawValue) || trim((string) $rawValue) === '') {
                    continue;
                }

                $rawValue = trim((string) $rawValue);
                $definition = $attributeDefinitions[$rawAttributeName] ?? null;
                $code = is_array($definition) ? (string) $definition['code'] : $this->attributeCodeFromWooName($rawAttributeName);
                $externalAttributeId = is_array($definition) ? (string) $definition['external_attribute_id'] : 'wojdak-'.$code;
                $optionText = is_array($definition) ? (string) data_get($definition, 'options.'.$rawValue, $rawValue) : $rawValue;

                $attributes[] = [
                    'code' => $code,
                    'name' => is_array($definition) ? (string) $definition['name'] : $this->attributeLabel($code),
                    'value' => $this->formatAttributeValue($code, $optionText),
                    'external_attribute_id' => $externalAttributeId,
                    'external_option_id' => $externalAttributeId.'-'.$this->optionSlug($rawValue),
                    'sort_order' => count($attributes),
                    'source_name' => $rawAttributeName,
                    'source_value' => $rawValue,
                ];
            }

            $imageUrl = $this->variationImageUrl($variation);
            $variationId = $variation['variation_id'] ?? null;
            $sku = $this->stringOrNull($variation['sku'] ?? null);

            $variations[] = [
                'external_variant_id' => is_scalar($variationId) && (string) $variationId !== ''
                    ? 'woocommerce-'.$variationId
                    : ($sku !== null ? 'sku-'.$sku : 'row-'.$index),
                'woocommerce_variation_id' => is_scalar($variationId) ? (string) $variationId : null,
                'sku' => $sku,
                'attributes' => $attributes,
                'price_gross_amount' => $this->moneyToMinorUnits($variation['display_price'] ?? null),
                'regular_price_gross_amount' => $this->moneyToMinorUnits($variation['display_regular_price'] ?? null),
                'is_in_stock' => (bool) ($variation['is_in_stock'] ?? false),
                'is_purchasable' => (bool) ($variation['is_purchasable'] ?? false),
                'is_active' => (bool) ($variation['variation_is_active'] ?? false),
                'is_visible' => (bool) ($variation['variation_is_visible'] ?? false),
                'max_qty' => is_numeric($variation['max_qty'] ?? null) ? (int) $variation['max_qty'] : null,
                'weight_grams' => $this->weightKgToGrams($variation['weight'] ?? null),
                'image_url' => $imageUrl,
            ];
        }

        return $variations;
    }

    private function variationImageUrl(array $variation): ?string
    {
        $image = $variation['image'] ?? null;

        if (! is_array($image)) {
            return null;
        }

        foreach (['full_src', 'url', 'src'] as $key) {
            $url = $this->normalizeUrl($this->stringOrNull($image[$key] ?? null));

            if ($url !== null && $this->isImageUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    private function moneyToMinorUnits(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }

    private function weightKgToGrams(mixed $weight): ?int
    {
        if (! is_numeric($weight)) {
            return null;
        }

        return (int) round(((float) $weight) * 1000);
    }

    private function attributeCodeFromWooName(string $rawAttributeName): string
    {
        $name = preg_replace('/^attribute_/i', '', trim($rawAttributeName)) ?: $rawAttributeName;
        $name = preg_replace('/^pa_/i', '', $name) ?: $name;

        return Str::slug($name, '_');
    }

    private function attributeLabel(string $code): string
    {
        return match ($code) {
            'rozmiar_damski' => 'Rozmiar damski',
            'rozmiar_meski' => 'Rozmiar męski',
            'rozmiar_obuwia_damskiego' => 'Rozmiar obuwia damskiego',
            'rozmiar_obuwia_meskiego' => 'Rozmiar obuwia męskiego',
            'wzrost_damski' => 'Wzrost damski',
            'wzrost_meski' => 'Wzrost męski',
            'kolory' => 'Kolor',
            'kolor_wstawek' => 'Kolor wstawek',
            'tkaniny' => 'Tkanina',
            'dlugosc_rekawa' => 'Długość rękawa',
            default => Str::headline(str_replace('_', ' ', $code)),
        };
    }

    private function formatAttributeValue(string $code, string $value): string
    {
        $value = $this->normalizeWhitespace($value) ?? $value;

        if (str_starts_with($code, 'wzrost_') && preg_match('/^(\d{3})-(\d{3})-(\d{3})\s*cm$/iu', $value, $matches) === 1) {
            return sprintf('%s (%s-%s cm)', $matches[1], $matches[2], $matches[3]);
        }

        return $value;
    }

    private function optionSlug(string $rawValue): string
    {
        return Str::slug(str_replace(['/', '\\'], '-', $rawValue), '-');
    }

    private function extractProductCategorySlug(Crawler $crawler): ?string
    {
        $slugs = [];

        try {
            $crawler->filter('body, .product')->each(function (Crawler $node) use (&$slugs): void {
                $class = $node->attr('class');

                if (! is_string($class) || trim($class) === '') {
                    return;
                }

                if (preg_match_all('/(?:^|\s)product_cat-([^\s]+)/u', $class, $matches) < 1) {
                    return;
                }

                foreach ($matches[1] as $slug) {
                    $slugs[$slug] = true;
                }
            });
        } catch (Throwable) {
            return null;
        }

        if ($slugs === []) {
            return null;
        }

        $broadCategorySlugs = [
            'odziez-medyczna',
            'odziez-damska',
            'odziez-meska',
            'obuwie-medyczne',
        ];

        foreach (array_keys($slugs) as $slug) {
            if (! in_array($slug, $broadCategorySlugs, true)) {
                return $slug;
            }
        }

        return array_key_first($slugs);
    }

    private function extractCategoryUrl(Crawler $crawler, ?string $categorySlug = null): ?string
    {
        $categoryUrls = [];

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$categoryUrls): void {
                $href = $node->attr('href');

                if (! is_string($href) || trim($href) === '') {
                    return;
                }

                $normalized = $this->normalizeUrl($href);

                if ($normalized === null) {
                    return;
                }

                $path = (string) parse_url($normalized, PHP_URL_PATH);

                if (str_contains($path, '/kategoria-produktu/') || str_contains($path, '/produkty/')) {
                    $categoryUrls[$this->normalizePathUrl($normalized)] = true;
                }
            });
        } catch (Throwable) {
            return null;
        }

        if ($categoryUrls === []) {
            return null;
        }

        if (is_string($categorySlug) && $categorySlug !== '') {
            foreach (array_keys($categoryUrls) as $categoryUrl) {
                if (str_contains((string) parse_url($categoryUrl, PHP_URL_PATH), '/'.$categorySlug.'/')) {
                    return $categoryUrl;
                }
            }
        }

        $categoryUrls = array_keys($categoryUrls);

        return end($categoryUrls) ?: null;
    }

    private function extractSizeTablePdfUrl(Crawler $crawler): ?string
    {
        $pdfUrl = null;

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$pdfUrl): void {
                if ($pdfUrl !== null) {
                    return;
                }

                $href = $node->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $normalized = $this->normalizeUrl($href);

                if ($normalized === null) {
                    return;
                }

                $lower = mb_strtolower($normalized);

                if (str_ends_with($lower, '.pdf') && str_contains($lower, 'tabela-rozmiar')) {
                    $pdfUrl = $normalized;
                }
            });
        } catch (Throwable) {
            return null;
        }

        return $pdfUrl;
    }

    /**
     * @param  array<int, array<string, mixed>>  $woocommerceVariations
     * @return array<int, string>
     */
    private function extractImages(Crawler $crawler, array $woocommerceVariations = []): array
    {
        $images = [];

        try {
            $crawler->filter('.woocommerce-product-gallery__image a[href], .single-product__gallery a.swiper-slide[href]')->each(function (Crawler $node) use (&$images): void {
                $href = $node->attr('href');
                $url = $this->normalizeUrl(is_string($href) ? $href : null);

                if ($url !== null && $this->isImageUrl($url)) {
                    $images[$url] = true;
                }
            });

            $crawler->filter('.woocommerce-product-gallery__image img[data-large_image], .woocommerce-product-gallery__image img[data-src]')->each(function (Crawler $node) use (&$images): void {
                foreach (['data-large_image', 'data-src', 'src'] as $attribute) {
                    $url = $this->normalizeUrl($node->attr($attribute));

                    if ($url !== null && $this->isImageUrl($url)) {
                        $images[$url] = true;

                        break;
                    }
                }
            });
        } catch (Throwable) {
            // Keep variation images below even if gallery parsing fails.
        }

        foreach ($woocommerceVariations as $variation) {
            $url = $this->normalizeUrl($this->stringOrNull($variation['image_url'] ?? null));

            if ($url !== null && $this->isImageUrl($url)) {
                $images[$url] = true;
            }
        }

        return array_keys($images);
    }

    private function cleanTitle(?string $title): ?string
    {
        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        $title = preg_replace('/\s+[-–—]\s+Wojdak\s*$/iu', '', $title) ?: $title;

        return $this->normalizeWhitespace($title);
    }

    private function externalIdFromUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/(?:product|produkt)/([^/]+)/?#i', $path, $matches) !== 1) {
            return null;
        }

        return mb_strtolower($matches[1]);
    }

    private function slugFromCategoryUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        return $segments === [] ? null : end($segments);
    }

    private function sizeTableType(?string $url, ?string $categoryUrl = null, ?string $categorySlug = null, ?string $name = null, ?string $descriptionText = null): ?string
    {
        $text = mb_strtolower(implode(' ', array_filter([$url, $categoryUrl, $categorySlug, $name, $descriptionText], 'is_string')));
        $ascii = Str::ascii($text);

        return match (true) {
            str_contains($ascii, 'obuwie') || str_contains($ascii, 'buty') => 'footwear',
            str_contains($ascii, 'odziez') || str_contains($ascii, 'bluza') || str_contains($ascii, 'fartuch') || str_contains($ascii, 'spodnie') || str_contains($ascii, 'spodnica') || str_contains($ascii, 'marynarka') => 'clothing',
            default => null,
        };
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = str_replace(['\\/', '\/'], '/', $url);

        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::WOJDAK_SHOP_HOST.$url;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    private function normalizePathUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = '/'.trim($path, '/').'/';
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return 'https://'.($host ?: self::WOJDAK_SHOP_HOST).$path;
    }

    private function isImageUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(jpe?g|png|webp|gif)$/i', $path) === 1;
    }

    private function normalizeWhitespace(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $value === '' ? null : $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function extractJsonLdValue(Crawler $crawler, string $key): ?string
    {
        try {
            foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
                $json = $script->textContent ?? '';
                $decoded = json_decode($json, true);

                if (! is_array($decoded)) {
                    continue;
                }

                $value = $this->findJsonLdProductValue($decoded, $key);

                if (is_scalar($value) && trim((string) $value) !== '') {
                    return $this->normalizeWhitespace((string) $value);
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function findJsonLdProductValue(array $node, string $key): mixed
    {
        if (($node['@type'] ?? null) === 'Product' && array_key_exists($key, $node)) {
            return $node[$key];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->findJsonLdProductValue($value, $key);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
