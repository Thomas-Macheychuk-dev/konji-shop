<?php

declare(strict_types=1);

namespace App\Services\Zamst;

use Closure;
use DOMElement;
use JsonException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ZamstProductScraper
{
    private const CANONICAL_HOST = 'zamst.com.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    private int $requestDelayMilliseconds = 500;

    private bool $verifyTls = true;

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

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->maxAttempts = max(1, $attempts);
        $this->retryDelayMilliseconds = max(0, $retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withTlsVerification(bool $verify): self
    {
        $this->verifyTls = $verify;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $productLinkContext = null): array
    {
        $failedUrls = [];
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;

        $this->emit('Fetching Zamst product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failedUrls);

        if ($html === null) {
            return $this->emptyResult(
                $sourceUrl,
                $productLinkContext,
                $failedUrls,
                ['Unable to fetch Zamst product page.'],
            );
        }

        return $this->extract($html, $sourceUrl, $productLinkContext, $failedUrls);
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @param  array<string, string>  $failedUrls
     * @return array<string, mixed>
     */
    public function extract(
        string $html,
        string $url,
        ?array $productLinkContext = null,
        array $failedUrls = [],
    ): array {
        $crawler = new Crawler($html, $url);
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;
        $canonical = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href');
        $canonicalUrl = is_string($canonical)
            ? ($this->normalizeProductUrl($canonical, $sourceUrl) ?? $sourceUrl)
            : $sourceUrl;
        $structuredProduct = $this->structuredProduct($crawler);
        $name = $this->extractName($crawler, $structuredProduct);
        $externalProductId = $this->extractExternalProductId($crawler, $structuredProduct);
        $slug = $this->productSlugFromUrl($canonicalUrl);
        $sku = $this->extractSku($crawler, $structuredProduct);
        $descriptionHtml = $this->extractDescriptionHtml($crawler);
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->stringValue($structuredProduct['description'] ?? null);
        $shortDescription = $this->normalizeText((string) ($seoDescription ?? ''));
        $price = $this->extractPrice($crawler, $structuredProduct);
        $currency = $this->extractCurrency($crawler, $structuredProduct) ?? 'PLN';
        $attributes = $this->extractAttributes($crawler);
        $variantCandidates = $this->extractVariantCandidates($crawler, $attributes, $currency);
        $availability = $this->extractAvailability($crawler, $structuredProduct, $variantCandidates);
        $galleryImages = $this->extractGalleryImages($crawler, $canonicalUrl, $name);
        $contentImages = $this->extractContentImages($crawler, $canonicalUrl, $name, $galleryImages);
        $images = $this->mergeImages($galleryImages, $contentImages);
        $downloads = $this->extractDownloads($crawler, $canonicalUrl);
        $videos = $this->extractVideos($crawler, $canonicalUrl);
        $categoryData = $this->extractCategories($crawler, $productLinkContext);
        $medicalDeviceClaim = $this->detectMedicalDeviceClaim($crawler, $descriptionHtml);
        $warnings = [];

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($externalProductId === null) {
            $warnings[] = 'WooCommerce product ID was not found.';
        }

        if ($price === null) {
            $warnings[] = 'Product price was not found.';
        }

        if ($images === []) {
            $warnings[] = 'No usable product images were found.';
        }

        $selectOptionCount = array_sum(array_map(
            static fn (array $attribute): int => count($attribute['options'] ?? []),
            $attributes,
        ));

        if ($selectOptionCount > 0 && $variantCandidates === []) {
            $warnings[] = 'Selectable options exist but WooCommerce exposed no concrete variation records.';
        }

        return [
            'source' => 'zamst',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $externalProductId,
            'external_id' => $externalProductId ?? $slug,
            'slug' => $slug,
            'name' => $name,
            'brand' => 'Zamst',
            'sku' => $sku,
            'seo_description' => $shortDescription !== '' ? $shortDescription : null,
            'short_description' => $shortDescription !== '' ? $shortDescription : null,
            'description_html' => $descriptionHtml,
            'price_gross_amount' => $price,
            'currency' => $currency,
            'availability' => $availability,
            'categories' => $categoryData['categories'],
            'category' => $categoryData['primary_category'],
            'source_categories' => $categoryData['source_categories'],
            'source_category_paths' => $categoryData['category_paths'],
            'attributes' => $attributes,
            'variant_candidates' => $variantCandidates,
            'variant_count' => count($variantCandidates),
            'gallery_images' => $galleryImages,
            'content_images' => $contentImages,
            'images' => $images,
            'downloads' => $downloads,
            'videos' => $videos,
            'is_medical_device' => $medicalDeviceClaim,
            'warnings' => $warnings,
            'failed_urls' => $failedUrls,
        ];
    }

    public function normalizeProductUrl(string $candidate, string $baseUrl = ZamstCategoryUrlScraper::DEFAULT_URL): ?string
    {
        $normalized = $this->normalizeUrl($candidate, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        return preg_match(
            '#^/produkt/[^/]+/$#u',
            rawurldecode((string) parse_url($normalized, PHP_URL_PATH)),
        ) === 1 ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractName(Crawler $crawler, array $structuredProduct): string
    {
        foreach ([
            '.summary.entry-summary h1',
            '#product-summary h1',
            'div[id^="product-"] h1.uk-h2',
            'h1.product_title',
            'h1.entry-title',
        ] as $selector) {
            $name = $this->normalizeText($crawler->filter($selector)->first()->text(''));

            if ($name !== '') {
                return $name;
            }
        }

        $structuredName = $this->stringValue($structuredProduct['name'] ?? null);

        if ($structuredName !== null) {
            return $structuredName;
        }

        $title = $this->normalizeText($crawler->filter('title')->first()->text(''));

        return trim((string) preg_replace('/\s+-\s+Zamst Polska.*$/iu', '', $title));
    }

    /**
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractExternalProductId(Crawler $crawler, array $structuredProduct): ?string
    {
        foreach ($crawler->filter('div[id^="product-"]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (preg_match('/^product-([0-9]+)$/', $node->getAttribute('id'), $matches) === 1) {
                return $matches[1];
            }
        }

        foreach ([
            ['form.variations_form[data-product_id]', 'data-product_id'],
            ['input[name="product_id"][value]', 'value'],
            ['button[name="add-to-cart"][value]', 'value'],
        ] as [$selector, $attribute]) {
            $value = $this->firstAttr($crawler, $selector, $attribute);

            if (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
                return trim($value);
            }
        }

        foreach (['sku', 'productID', 'productId', 'id'] as $key) {
            $value = $this->stringValue($structuredProduct[$key] ?? null);

            if ($value !== null && preg_match('/^[0-9]+$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractSku(Crawler $crawler, array $structuredProduct): ?string
    {
        foreach (['.product_meta .sku', '.sku'] as $selector) {
            $value = $this->normalizeText($crawler->filter($selector)->first()->text(''));

            if ($value !== '' && ! in_array(mb_strtolower($value), ['brak', 'n/a'], true)) {
                return $value;
            }
        }

        $value = $this->stringValue($structuredProduct['sku'] ?? null);

        return $value !== null && ! ctype_digit($value) ? $value : null;
    }

    private function extractDescriptionHtml(Crawler $crawler): ?string
    {
        foreach ([
            '.woocommerce-product-details__short-description .uk-switcher > li:first-child',
            '.woocommerce-product-details__short-description ul.uk-switcher > li:first-child',
            '.woocommerce-product-details__short-description > div:first-child',
            '.woocommerce-product-details__short-description',
            '.woocommerce-Tabs-panel--description',
        ] as $selector) {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                continue;
            }

            $html = $this->innerHtml($node);

            if ($this->normalizeText(strip_tags($html)) !== '') {
                return trim($html);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractPrice(Crawler $crawler, array $structuredProduct): ?float
    {
        foreach ([
            '.summary.entry-summary p.price',
            '.summary.entry-summary .price',
            'p.price',
            '.price',
        ] as $selector) {
            $value = $this->parseMoney($crawler->filter($selector)->first()->text(''));

            if ($value !== null) {
                return $value;
            }
        }

        $offers = $structuredProduct['offers'] ?? null;

        if (is_array($offers)) {
            if (array_is_list($offers)) {
                foreach ($offers as $offer) {
                    if (is_array($offer)) {
                        $price = $this->numericValue($offer['price'] ?? $offer['lowPrice'] ?? null);

                        if ($price !== null) {
                            return $price;
                        }
                    }
                }
            } else {
                return $this->numericValue($offers['price'] ?? $offers['lowPrice'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractCurrency(Crawler $crawler, array $structuredProduct): ?string
    {
        $metaCurrency = $this->firstAttr(
            $crawler,
            'meta[property="product:price:currency"][content], meta[itemprop="priceCurrency"][content]',
            'content',
        );

        if (is_string($metaCurrency) && trim($metaCurrency) !== '') {
            return mb_strtoupper(trim($metaCurrency));
        }

        $offers = $structuredProduct['offers'] ?? null;

        if (is_array($offers)) {
            if (array_is_list($offers)) {
                foreach ($offers as $offer) {
                    if (! is_array($offer)) {
                        continue;
                    }

                    $currency = $this->stringValue($offer['priceCurrency'] ?? null);

                    if ($currency !== null) {
                        return mb_strtoupper($currency);
                    }
                }
            } else {
                $currency = $this->stringValue($offers['priceCurrency'] ?? null);

                if ($currency !== null) {
                    return mb_strtoupper($currency);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @param  array<string, mixed>  $structuredProduct
     */
    private function extractAvailability(Crawler $crawler, array $structuredProduct, array $variants): ?string
    {
        if ($variants !== []) {
            foreach ($variants as $variant) {
                if (($variant['availability'] ?? null) === 'in_stock') {
                    return 'in_stock';
                }
            }

            return 'out_of_stock';
        }

        foreach (['.summary .stock', '.stock'] as $selector) {
            $label = mb_strtolower($this->normalizeText($crawler->filter($selector)->first()->text('')));

            if ($label === '') {
                continue;
            }

            if (str_contains($label, 'brak') || str_contains($label, 'out of stock') || str_contains($label, 'niedost')) {
                return 'out_of_stock';
            }

            if (str_contains($label, 'dost') || str_contains($label, 'in stock')) {
                return 'in_stock';
            }
        }

        $availability = null;
        $offers = $structuredProduct['offers'] ?? null;

        if (is_array($offers)) {
            if (array_is_list($offers)) {
                foreach ($offers as $offer) {
                    if (is_array($offer) && is_string($offer['availability'] ?? null)) {
                        $availability = $offer['availability'];
                        break;
                    }
                }
            } elseif (is_string($offers['availability'] ?? null)) {
                $availability = $offers['availability'];
            }
        }

        if (! is_string($availability)) {
            return null;
        }

        return str_contains(mb_strtolower($availability), 'instock') ? 'in_stock' : 'out_of_stock';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractAttributes(Crawler $crawler): array
    {
        $attributes = [];

        foreach ($crawler->filter('form.variations_form select[name^="attribute_"]') as $selectNode) {
            if (! $selectNode instanceof DOMElement) {
                continue;
            }

            $select = new Crawler($selectNode);
            $name = $selectNode->getAttribute('name');
            $code = preg_replace('/^attribute_(?:pa_)?/', '', $name) ?: $name;
            $code = str_replace('_', '-', $code);
            $id = $selectNode->getAttribute('id');
            $label = '';

            if ($id !== '') {
                $label = $this->normalizeText($crawler->filter('label[for="'.$id.'"]')->first()->text(''));
            }

            if ($label === '') {
                $label = $this->humanizeSlug($code);
            }

            $options = [];

            foreach ($select->filter('option[value]') as $optionNode) {
                if (! $optionNode instanceof DOMElement) {
                    continue;
                }

                $value = trim($optionNode->getAttribute('value'));

                if ($value === '') {
                    continue;
                }

                $option = new Crawler($optionNode);
                $optionLabel = $this->normalizeText($option->text(''));
                $options[$value] = [
                    'value' => $value,
                    'label' => $optionLabel !== '' ? $optionLabel : $this->humanizeSlug($value),
                ];
            }

            $attributes[] = [
                'code' => $code,
                'source_name' => $name,
                'label' => $label,
                'options' => array_values($options),
            ];
        }

        return $attributes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @return array<int, array<string, mixed>>
     */
    private function extractVariantCandidates(Crawler $crawler, array $attributes, string $currency): array
    {
        $form = $crawler->filter('form.variations_form[data-product_variations]')->first();

        if ($form->count() === 0) {
            return [];
        }

        $encoded = $form->attr('data-product_variations');

        if (! is_string($encoded) || trim($encoded) === '') {
            return [];
        }

        $decoded = $this->decodeHtmlJson($encoded);

        if (! is_array($decoded)) {
            return [];
        }

        $optionLabels = [];
        $attributeLabels = [];

        foreach ($attributes as $attribute) {
            $sourceName = (string) ($attribute['source_name'] ?? '');
            $code = (string) ($attribute['code'] ?? '');
            $attributeLabels[$sourceName] = (string) ($attribute['label'] ?? $this->humanizeSlug($code));

            foreach ($attribute['options'] ?? [] as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $optionLabels[$sourceName][(string) ($option['value'] ?? '')] = (string) ($option['label'] ?? '');
            }
        }

        $variants = [];

        foreach ($decoded as $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $variationId = $this->stringValue($variation['variation_id'] ?? null);

            if ($variationId === null) {
                continue;
            }

            $variantAttributes = [];

            foreach (($variation['attributes'] ?? []) as $sourceName => $value) {
                if (! is_string($sourceName) || ! is_scalar($value)) {
                    continue;
                }

                $value = trim((string) $value);
                $code = preg_replace('/^attribute_(?:pa_)?/', '', $sourceName) ?: $sourceName;
                $code = str_replace('_', '-', $code);
                $label = $attributeLabels[$sourceName] ?? $this->humanizeSlug($code);
                $valueLabel = $optionLabels[$sourceName][$value] ?? $this->humanizeSlug($value);

                $variantAttributes[] = [
                    'code' => $code,
                    'source_name' => $sourceName,
                    'label' => $label,
                    'value' => $value,
                    'value_label' => $valueLabel,
                ];
            }

            $image = is_array($variation['image'] ?? null) ? $variation['image'] : [];
            $imageUrl = $this->normalizeAssetUrl((string) (
                $image['full_src'] ?? $image['url'] ?? $image['src'] ?? ''
            ));
            $inStock = (bool) ($variation['is_in_stock'] ?? false);
            $active = ! array_key_exists('variation_is_active', $variation) || (bool) $variation['variation_is_active'];
            $visible = ! array_key_exists('variation_is_visible', $variation) || (bool) $variation['variation_is_visible'];

            $variants[] = [
                'external_variant_id' => $variationId,
                'sku' => $this->stringValue($variation['sku'] ?? null),
                'attributes' => $variantAttributes,
                'price_gross_amount' => $this->numericValue($variation['display_price'] ?? null),
                'regular_price_gross_amount' => $this->numericValue($variation['display_regular_price'] ?? null),
                'currency' => $currency,
                'availability' => $inStock ? 'in_stock' : 'out_of_stock',
                'in_stock' => $inStock,
                'purchasable' => (bool) ($variation['is_purchasable'] ?? false),
                'active' => $active,
                'visible' => $visible,
                'stock_quantity' => is_numeric($variation['max_qty'] ?? null) && (int) $variation['max_qty'] > 0
                    ? (int) $variation['max_qty']
                    : null,
                'min_quantity' => is_numeric($variation['min_qty'] ?? null)
                    ? (int) $variation['min_qty']
                    : null,
                'image' => $imageUrl !== null ? [
                    'url' => $imageUrl,
                    'alt' => $this->stringValue($image['alt'] ?? null),
                    'title' => $this->stringValue($image['title'] ?? null),
                ] : null,
            ];
        }

        return $variants;
    }

    /**
     * @return array<int, array{url: string, alt: string|null, title: string|null}>
     */
    private function extractGalleryImages(Crawler $crawler, string $baseUrl, string $name): array
    {
        $images = [];
        $selectors = [
            '.woocommerce-product-gallery a[href] img',
            '.woocommerce-product-gallery img',
            'figure.woocommerce-product-gallery__wrapper img',
            'img.wp-post-image',
            '.uk-thumbnav img',
        ];

        foreach ($selectors as $selector) {
            foreach ($crawler->filter($selector) as $imageNode) {
                if (! $imageNode instanceof DOMElement) {
                    continue;
                }

                $image = new Crawler($imageNode);
                $candidate = null;

                foreach (['data-large_image', 'data-large-image'] as $attribute) {
                    $value = $imageNode->getAttribute($attribute);

                    if ($value !== '') {
                        $candidate = $this->normalizeAssetUrl($value, $baseUrl);
                    }

                    if ($candidate !== null) {
                        break;
                    }
                }

                if ($candidate === null) {
                    $parent = $image->ancestors()->filter('a[href]')->first();
                    $href = $parent->count() > 0 ? $parent->attr('href') : null;
                    $candidate = is_string($href) ? $this->normalizeAssetUrl($href, $baseUrl) : null;
                }

                if ($candidate === null) {
                    foreach (['data-src', 'data-lazy-src', 'src'] as $attribute) {
                        $value = $imageNode->getAttribute($attribute);

                        if ($value !== '') {
                            $candidate = $this->normalizeAssetUrl($value, $baseUrl);
                        }

                        if ($candidate !== null) {
                            break;
                        }
                    }
                }

                $this->addImage(
                    $images,
                    $candidate,
                    $this->normalizeText($imageNode->getAttribute('alt')) ?: ($name !== '' ? $name : null),
                    $this->normalizeText($imageNode->getAttribute('title')) ?: null,
                );
            }
        }

        return array_values($images);
    }

    /**
     * @param  array<int, array{url: string, alt: string|null, title: string|null}>  $galleryImages
     * @return array<int, array{url: string, alt: string|null, title: string|null}>
     */
    private function extractContentImages(Crawler $crawler, string $baseUrl, string $name, array $galleryImages): array
    {
        $galleryUrls = array_fill_keys(array_column($galleryImages, 'url'), true);
        $images = [];

        foreach ([
            '.woocommerce-product-details__short-description img',
            '.woocommerce-Tabs-panel img',
            '.entry-content img',
        ] as $selector) {
            foreach ($crawler->filter($selector) as $imageNode) {
                if (! $imageNode instanceof DOMElement) {
                    continue;
                }

                $candidate = null;

                foreach (['data-large_image', 'data-src', 'data-lazy-src', 'src'] as $attribute) {
                    $value = $imageNode->getAttribute($attribute);

                    if ($value !== '') {
                        $candidate = $this->normalizeAssetUrl($value, $baseUrl);
                    }

                    if ($candidate !== null) {
                        break;
                    }
                }

                if ($candidate === null || isset($galleryUrls[$candidate])) {
                    continue;
                }

                $this->addImage(
                    $images,
                    $candidate,
                    $this->normalizeText($imageNode->getAttribute('alt')) ?: ($name !== '' ? $name : null),
                    $this->normalizeText($imageNode->getAttribute('title')) ?: null,
                );
            }
        }

        return array_values($images);
    }

    /**
     * @param  array<int, array{url: string, alt: string|null, title: string|null}>  $gallery
     * @param  array<int, array{url: string, alt: string|null, title: string|null}>  $content
     * @return array<int, array{url: string, alt: string|null, title: string|null}>
     */
    private function mergeImages(array $gallery, array $content): array
    {
        $images = [];

        foreach (array_merge($gallery, $content) as $image) {
            $images[$image['url']] = $image;
        }

        return array_values($images);
    }

    /**
     * @return array<int, array{url: string, label: string|null, extension: string|null}>
     */
    private function extractDownloads(Crawler $crawler, string $baseUrl): array
    {
        $downloads = [];

        foreach ($crawler->filter('a[href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->normalizeAssetUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null) {
                continue;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            $extension = mb_strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if (! in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
                continue;
            }

            $link = new Crawler($node);
            $downloads[$url] = [
                'url' => $url,
                'label' => $this->normalizeText($link->text('')) ?: null,
                'extension' => $extension,
            ];
        }

        return array_values($downloads);
    }

    /**
     * @return array<int, array{url: string, label: string|null}>
     */
    private function extractVideos(Crawler $crawler, string $baseUrl): array
    {
        $videos = [];

        foreach ($crawler->filter('a[href], iframe[src]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $candidate = $node->tagName === 'iframe'
                ? $node->getAttribute('src')
                : $node->getAttribute('href');
            $url = $this->normalizeExternalAssetUrl($candidate, $baseUrl);

            if ($url === null || ! $this->isProductVideoUrl($url)) {
                continue;
            }

            $link = new Crawler($node);
            $videos[$url] = [
                'url' => $url,
                'label' => $this->normalizeText($link->text('')) ?: null,
            ];
        }

        return array_values($videos);
    }

    private function isProductVideoUrl(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            return trim($path, '/') !== '';
        }

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if (preg_match('#^/(?:@|channel/|user/|c/)#i', $path) === 1) {
                return false;
            }

            return $path === '/watch'
                ? str_contains($query, 'v=')
                : preg_match('#^/(?:embed|shorts|live)/[^/]+#i', $path) === 1;
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            return preg_match('#/(?:video/)?[0-9]+#', $path) === 1;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @return array{categories: array<int, string>, primary_category: string|null, source_categories: array<int, array<string, mixed>>, category_paths: array<int, array<int, string>>}
     */
    private function extractCategories(Crawler $crawler, ?array $productLinkContext): array
    {
        $sourceCategories = [];
        $categoryPaths = [];

        foreach (($productLinkContext['source_categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = (string) ($category['external_category_id'] ?? $category['url'] ?? '');

            if ($id === '') {
                continue;
            }

            $sourceCategories[$id] = $category;
            $path = $this->stringList($category['path'] ?? []);

            if ($path !== []) {
                $categoryPaths[implode('\0', $path)] = $path;
            }
        }

        foreach (($productLinkContext['category_paths'] ?? []) as $path) {
            $path = $this->stringList($path);

            if ($path !== []) {
                $categoryPaths[implode('\0', $path)] = $path;
            }
        }

        foreach ($crawler->filter('.product_meta .posted_in a[href*="/kategoria-produktu/"], .posted_in a[href*="/kategoria-produktu/"]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->normalizeCategoryUrl($node->getAttribute('href'));

            if ($url === null) {
                continue;
            }

            $link = new Crawler($node);
            $name = $this->normalizeText($link->text(''));
            $externalId = $this->categoryExternalId($url);

            if ($externalId === null) {
                continue;
            }

            $sourceCategories[$externalId] = [
                'source' => 'zamst',
                'external_category_id' => $externalId,
                'slug' => basename($externalId),
                'name' => $name !== '' ? $name : $this->humanizeSlug((string) basename($externalId)),
                'url' => $url,
                'path' => [$name !== '' ? $name : $this->humanizeSlug((string) basename($externalId))],
                'level' => count(array_filter(explode('/', $externalId))),
            ];

            $leafPath = [$sourceCategories[$externalId]['name']];
            $categoryPaths[implode('\0', $leafPath)] = $leafPath;
        }

        $categories = [];

        foreach ($sourceCategories as $category) {
            $name = $this->normalizeText((string) ($category['name'] ?? ''));

            if ($name !== '') {
                $categories[$name] = true;
            }
        }

        $categories = array_keys($categories);
        $primaryCategory = null;

        if ($categoryPaths !== []) {
            $longest = array_values($categoryPaths);
            usort($longest, static fn (array $a, array $b): int => count($b) <=> count($a));
            $primaryCategory = $longest[0][count($longest[0]) - 1] ?? null;
        } elseif ($categories !== []) {
            $primaryCategory = $categories[0];
        }

        return [
            'categories' => $categories,
            'primary_category' => is_string($primaryCategory) ? $primaryCategory : null,
            'source_categories' => array_values($sourceCategories),
            'category_paths' => array_values($categoryPaths),
        ];
    }

    private function detectMedicalDeviceClaim(Crawler $crawler, ?string $descriptionHtml): ?bool
    {
        $text = $this->normalizeText(
            $crawler->filter('.summary.entry-summary, .woocommerce-product-details__short-description')->text('').' '.strip_tags((string) $descriptionHtml),
        );
        $lower = mb_strtolower($text);

        if (preg_match('/\b(?:wyrób|wyrob|wyroby)\s+medyczn/iu', $lower) === 1) {
            return true;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredProduct(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $json = trim($node->textContent);

            if ($json === '') {
                continue;
            }

            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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

        $type = $value['@type'] ?? null;

        if ($type === 'Product' || (is_array($type) && in_array('Product', $type, true))) {
            return $value;
        }

        foreach ($value as $child) {
            $found = $this->findStructuredProduct($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function normalizeCategoryUrl(string $candidate): ?string
    {
        $normalized = $this->normalizeUrl($candidate, ZamstCategoryUrlScraper::DEFAULT_URL);

        if ($normalized === null) {
            return null;
        }

        return preg_match(
            '#^/kategoria-produktu/.+/$#u',
            rawurldecode((string) parse_url($normalized, PHP_URL_PATH)),
        ) === 1 ? $normalized : null;
    }

    private function categoryExternalId(string $url): ?string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        return preg_match('#^/kategoria-produktu/(.+)/$#u', $path, $matches) === 1
            ? trim($matches[1], '/')
            : null;
    }

    private function normalizeUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim(html_entity_decode(str_replace('\\/', '/', $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with(mb_strtolower($candidate), 'javascript:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
            $baseDirectory = str_ends_with($basePath, '/')
                ? rtrim($basePath, '/')
                : rtrim(dirname($basePath), '/');
            $candidate = 'https://'.self::CANONICAL_HOST.($baseDirectory !== '' ? $baseDirectory.'/' : '/').$candidate;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts)) {
            return null;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, [self::CANONICAL_HOST, 'www.'.self::CANONICAL_HOST], true)) {
            return null;
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', (string) ($parts['path'] ?? '/'))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = rawurlencode(rawurldecode($segment));
        }

        $path = '/'.implode('/', $segments);

        if ($path !== '/' && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return 'https://'.self::CANONICAL_HOST.$path;
    }

    private function normalizeAssetUrl(string $candidate, string $baseUrl = ZamstCategoryUrlScraper::DEFAULT_URL): ?string
    {
        $candidate = trim(html_entity_decode(str_replace('\\/', '/', $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, 'data:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            return null;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || ! in_array(mb_strtolower((string) ($parts['host'] ?? '')), [self::CANONICAL_HOST, 'www.'.self::CANONICAL_HOST], true)) {
            return null;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'https' : 'https';
        $path = (string) ($parts['path'] ?? '/');

        return $scheme.'://'.self::CANONICAL_HOST.$path;
    }

    private function normalizeExternalAssetUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim(html_entity_decode(str_replace('\\/', '/', $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, 'data:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return 'https:'.$candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return 'https://'.self::CANONICAL_HOST.$candidate;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        $base = parse_url($baseUrl);

        if (! is_array($base)) {
            return null;
        }

        return 'https://'.self::CANONICAL_HOST.'/'.ltrim($candidate, '/');
    }

    private function productSlugFromUrl(string $url): string
    {
        $segments = explode('/', trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/'));

        return (string) (end($segments) ?: 'unknown');
    }

    private function parseMoney(string $value): ?float
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/([0-9][0-9\s\x{00A0}.]*(?:,[0-9]{1,2})?)/u', $value, $matches) !== 1) {
            return null;
        }

        $number = str_replace(["\xC2\xA0", ' ', '.'], '', $matches[1]);
        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? (float) $number : null;
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
            return (float) str_replace(',', '.', trim($value));
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = $this->normalizeText((string) $value);

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
            if (! is_scalar($entry)) {
                continue;
            }

            $entry = $this->normalizeText((string) $entry);

            if ($entry !== '') {
                $strings[] = $entry;
            }
        }

        return array_values(array_unique($strings));
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        $node = $crawler->filter($selector)->first();

        if ($node->count() === 0) {
            return null;
        }

        $value = $node->attr($attribute);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        return $this->firstAttr($crawler, 'meta[name="'.$name.'"][content]', 'content');
    }

    private function innerHtml(Crawler $crawler): string
    {
        $node = $crawler->getNode(0);

        if (! $node instanceof DOMElement || $node->ownerDocument === null) {
            return '';
        }

        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    /**
     * @param  array<string, array{url: string, alt: string|null, title: string|null}>  $images
     */
    private function addImage(array &$images, ?string $url, ?string $alt, ?string $title): void
    {
        if ($url === null || !$this->looksLikeImage($url)) {
            return;
        }

        $images[$url] = [
            'url' => $url,
            'alt' => $alt !== null && trim($alt) !== '' ? trim($alt) : null,
            'title' => $title !== null && trim($title) !== '' ? trim($title) : null,
        ];
    }

    private function looksLikeImage(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/i', $path) === 1;
    }

    private function decodeHtmlJson(string $value): mixed
    {
        $candidates = [
            $value,
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];

        foreach ($candidates as $candidate) {
            try {
                return json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
        }

        return null;
    }

    private function humanizeSlug(string $slug): string
    {
        return mb_convert_case(
            $this->normalizeText(str_replace(['-', '_'], ' ', rawurldecode($slug))),
            MB_CASE_TITLE,
            'UTF-8',
        );
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @param  array<string, string>  $failedUrls
     */
    private function fetchBody(string $url, array &$failedUrls): ?string
    {
        $lastFailure = 'Unknown HTTP failure';

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withOptions(['verify' => $this->verifyTls])
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            if ($response->successful()) {
                unset($failedUrls[$url]);

                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if ($attempt < $this->maxAttempts && ($response->status() === 429 || $response->serverError())) {
                $this->pauseBeforeRetry();
                continue;
            }

            break;
        }

        $failedUrls[$url] = $lastFailure;

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopZamstCrawler/1.0)',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @param  array<string, string>  $failedUrls
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function emptyResult(
        string $url,
        ?array $productLinkContext,
        array $failedUrls,
        array $warnings,
    ): array {
        return [
            'source' => 'zamst',
            'source_url' => $url,
            'canonical_url' => $url,
            'external_product_id' => null,
            'external_id' => $this->productSlugFromUrl($url),
            'slug' => $this->productSlugFromUrl($url),
            'name' => '',
            'brand' => 'Zamst',
            'sku' => null,
            'seo_description' => null,
            'short_description' => null,
            'description_html' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => null,
            'categories' => [],
            'category' => null,
            'source_categories' => is_array($productLinkContext['source_categories'] ?? null)
                ? $productLinkContext['source_categories']
                : [],
            'source_category_paths' => is_array($productLinkContext['category_paths'] ?? null)
                ? $productLinkContext['category_paths']
                : [],
            'attributes' => [],
            'variant_candidates' => [],
            'variant_count' => 0,
            'gallery_images' => [],
            'content_images' => [],
            'images' => [],
            'downloads' => [],
            'videos' => [],
            'is_medical_device' => null,
            'warnings' => $warnings,
            'failed_urls' => $failedUrls,
        ];
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

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
