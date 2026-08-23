<?php

declare(strict_types=1);

namespace App\Services\Zamst;

use Illuminate\Support\Str;

final class ZamstImportMapper
{
    private const SOURCE = 'zamst';

    private const BRAND = 'Zamst';

    private const DEFAULT_VAT_RATE = 23;

    private const MEDICAL_DEVICE_VAT_RATE = 8;

    /**
     * @param  array<string, mixed>  $catalogue
     * @return array<string, mixed>
     */
    public function mapCatalogue(array $catalogue, ?int $limit = null, int $offset = 0): array
    {
        $sourceProducts = is_array($catalogue['products'] ?? null)
            ? array_values(array_filter($catalogue['products'], 'is_array'))
            : [];
        $selectedProducts = array_slice($sourceProducts, max(0, $offset), $limit === null ? null : max(1, $limit));
        $mappedProducts = [];
        $errors = [];
        $reviewItems = [];
        $seenProductIds = [];
        $seenVariantIds = [];
        $categoryPaths = [];
        $sourceVariantCount = 0;
        $plannedVariantCount = 0;
        $imageCount = 0;
        $downloadCount = 0;
        $videoCount = 0;
        $filteredVideoCount = 0;
        $vatReviewCount = 0;

        foreach ($selectedProducts as $index => $scraped) {
            $mapped = $this->mapProduct($scraped);
            $mappedProducts[] = $mapped;
            $label = $mapped['product']['name'] ?? 'Product '.($index + 1);
            $externalProductId = $mapped['product']['external_id'] ?? null;

            foreach ($mapped['errors'] as $error) {
                $errors[] = $label.': '.$error;
            }

            foreach ($mapped['review_items'] as $reviewItem) {
                $reviewItems[] = $label.': '.$reviewItem;
            }

            if (is_string($externalProductId) && $externalProductId !== '') {
                if (isset($seenProductIds[$externalProductId])) {
                    $errors[] = $label.': duplicate external product ID '.$externalProductId.'.';
                }

                $seenProductIds[$externalProductId] = true;
            }

            foreach ($mapped['categories'] as $category) {
                $path = is_array($category['path'] ?? null) ? $category['path'] : [];

                if ($path !== []) {
                    $categoryPaths[implode(' > ', $path)] = true;
                }
            }

            $sourceVariantCount += (int) ($mapped['source_variant_count'] ?? 0);
            $plannedVariantCount += count($mapped['variants']);
            $imageCount += count($mapped['images']);
            $downloadCount += count($mapped['downloads']);
            $videoCount += count($mapped['videos']);
            $filteredVideoCount += (int) ($mapped['filtered_non_product_video_count'] ?? 0);

            if (($mapped['tax']['requires_review'] ?? false) === true) {
                $vatReviewCount++;
            }

            foreach ($mapped['variants'] as $variant) {
                $externalVariantId = $variant['external_variant_id'] ?? null;

                if (! is_string($externalVariantId) || $externalVariantId === '') {
                    continue;
                }

                if (isset($seenVariantIds[$externalVariantId])) {
                    $errors[] = $label.': duplicate planned external variant ID '.$externalVariantId.'.';
                }

                $seenVariantIds[$externalVariantId] = true;
            }
        }

        $errors = array_values(array_unique($errors));
        $reviewItems = array_values(array_unique($reviewItems));

        return [
            'source' => self::SOURCE,
            'mode' => 'import_mapping_dry_run',
            'database_writes' => false,
            'images_downloaded' => false,
            'source_product_count' => count($sourceProducts),
            'selected_product_count' => count($selectedProducts),
            'mapped_product_count' => count($mappedProducts),
            'products' => $mappedProducts,
            'summary' => [
                'unique_external_product_ids' => count($seenProductIds),
                'source_variants' => $sourceVariantCount,
                'planned_variants' => $plannedVariantCount,
                'unique_planned_external_variant_ids' => count($seenVariantIds),
                'distinct_category_paths' => count($categoryPaths),
                'category_paths' => array_keys($categoryPaths),
                'images' => $imageCount,
                'downloads' => $downloadCount,
                'product_videos' => $videoCount,
                'filtered_non_product_videos' => $filteredVideoCount,
                'vat_review_products' => $vatReviewCount,
                'manufacturer' => self::BRAND,
                'planned_product_status' => 'draft',
                'planned_variant_status' => 'draft',
            ],
            'errors' => $errors,
            'review_items' => $reviewItems,
            'ready_for_local_import_implementation' => $mappedProducts !== [] && $errors === [],
        ];
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array<string, mixed>
     */
    public function mapProduct(array $scraped): array
    {
        $errors = [];
        $reviewItems = [];
        $externalProductId = $this->stringOrNull($scraped['external_product_id'] ?? $scraped['external_id'] ?? null);
        $name = $this->stringOrNull($scraped['name'] ?? null);
        $slug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: ($name !== null ? Str::slug($name) : null);
        $price = $this->numericOrNull($scraped['price_gross_amount'] ?? null);
        $currency = mb_strtoupper($this->stringOrNull($scraped['currency'] ?? null) ?? 'PLN');
        $availability = $this->availability($scraped['availability'] ?? null);
        $images = $this->images($scraped);
        $categories = $this->categories($scraped);
        $videos = $this->videos($scraped['videos'] ?? []);
        $filteredVideoCount = max(0, $this->arrayCount($scraped['videos'] ?? []) - count($videos));
        $downloads = $this->downloads($scraped['downloads'] ?? []);
        $medicalDevice = $this->nullableBoolean($scraped['is_medical_device'] ?? null);
        $vatRate = $medicalDevice === true ? self::MEDICAL_DEVICE_VAT_RATE : self::DEFAULT_VAT_RATE;
        $requiresVatReview = $medicalDevice === null;

        if ($externalProductId === null) {
            $errors[] = 'external product ID is missing.';
        }

        if ($name === null) {
            $errors[] = 'product name is missing.';
        }

        if ($slug === null || $slug === '') {
            $errors[] = 'product slug cannot be resolved.';
        }

        if ($price === null || $price <= 0) {
            $errors[] = 'positive gross price is missing.';
        }

        if ($currency !== 'PLN') {
            $errors[] = 'unsupported currency '.$currency.'.';
        }

        if ($images === []) {
            $errors[] = 'no product images are mapped.';
        }

        if ($categories === []) {
            $errors[] = 'no category path is mapped.';
        }

        if ($requiresVatReview) {
            $reviewItems[] = 'source does not state whether this is a medical device; mapping currently falls back to 23% VAT.';
        }

        if ($filteredVideoCount > 0) {
            $reviewItems[] = $filteredVideoCount.' non-product YouTube channel/profile link(s) were excluded.';
        }

        $variants = $this->variants(
            $scraped,
            $externalProductId,
            $price,
            $currency,
            $availability,
            $vatRate,
            $errors,
            $reviewItems,
        );
        $attributes = $this->attributes($variants);
        $grossMinor = $price !== null ? $this->moneyToMinorUnits($price) : null;

        return [
            'source' => self::SOURCE,
            'source_url' => $this->stringOrNull($scraped['source_url'] ?? null),
            'canonical_url' => $this->stringOrNull($scraped['canonical_url'] ?? null),
            'product' => [
                'external_source' => self::SOURCE,
                'external_id' => $externalProductId,
                'external_parent_sku' => $externalProductId !== null ? 'ZAMST-'.$externalProductId : null,
                'name' => $name,
                'slug' => $slug,
                'status' => 'draft',
                'short_description_html' => $this->paragraphHtml($this->stringOrNull($scraped['short_description'] ?? null)),
                'description_html' => $this->stringOrNull($scraped['description_html'] ?? null),
                'seo_title' => $name,
                'seo_description' => $this->stringOrNull($scraped['seo_description'] ?? null),
                'manufacturer' => self::BRAND,
            ],
            'tax' => [
                'is_medical_device' => $medicalDevice,
                'vat_rate' => $vatRate,
                'requires_review' => $requiresVatReview,
                'gross_minor' => $grossMinor,
                'net_minor' => $grossMinor !== null ? $this->netFromGross($grossMinor, $vatRate) : null,
                'currency' => $currency,
            ],
            'categories' => $categories,
            'attributes' => $attributes,
            'source_variant_count' => $this->arrayCount($scraped['variant_candidates'] ?? []),
            'variants' => $variants,
            'images' => $images,
            'downloads' => $downloads,
            'videos' => $videos,
            'filtered_non_product_video_count' => $filteredVideoCount,
            'errors' => array_values(array_unique($errors)),
            'review_items' => array_values(array_unique($reviewItems)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function attributes(array $variants): array
    {
        $attributes = [
            'producent' => [
                'code' => 'producent',
                'label' => 'Producent',
                'values' => ['zamst' => [
                    'value' => 'zamst',
                    'value_label' => 'Zamst',
                ]],
                'source' => 'fixed',
            ],
        ];

        foreach ($variants as $variant) {
            foreach ($variant['attributes'] ?? [] as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }

                $code = $this->attributeCode($attribute);
                $label = $this->stringOrNull($attribute['label'] ?? null) ?: $this->humanize($code);
                $value = $this->stringOrNull($attribute['value'] ?? null);
                $valueLabel = $this->stringOrNull($attribute['value_label'] ?? null) ?: $value;

                if ($code === '' || $value === null || $valueLabel === null) {
                    continue;
                }

                $attributes[$code] ??= [
                    'code' => $code,
                    'label' => $label,
                    'values' => [],
                    'source' => 'concrete_variants',
                ];
                $attributes[$code]['values'][$value] = [
                    'value' => $value,
                    'value_label' => $valueLabel,
                ];
            }
        }

        return array_values(array_map(
            static function (array $attribute): array {
                $attribute['values'] = array_values($attribute['values']);

                return $attribute;
            },
            $attributes,
        ));
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $reviewItems
     * @return list<array<string, mixed>>
     */
    private function variants(
        array $scraped,
        ?string $externalProductId,
        ?float $productPrice,
        string $currency,
        string $productAvailability,
        int $vatRate,
        array &$errors,
        array &$reviewItems,
    ): array {
        $sourceVariants = is_array($scraped['variant_candidates'] ?? null)
            ? array_values(array_filter($scraped['variant_candidates'], 'is_array'))
            : [];

        if ($sourceVariants === []) {
            if ($externalProductId === null) {
                return [];
            }

            $grossMinor = $productPrice !== null ? $this->moneyToMinorUnits($productPrice) : null;

            return [[
                'source_external_variant_id' => null,
                'external_variant_id' => 'zamst-'.$externalProductId.'-default',
                'sku' => $this->stringOrNull($scraped['sku'] ?? null) ?: 'ZAMST-'.$externalProductId,
                'status' => 'draft',
                'is_default' => true,
                'price_gross_minor' => $grossMinor,
                'price_net_minor' => $grossMinor !== null ? $this->netFromGross($grossMinor, $vatRate) : null,
                'currency' => $currency,
                'vat_rate' => $vatRate,
                'stock_status' => $productAvailability,
                'attributes' => [],
                'image' => null,
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ]];
        }

        $mapped = [];
        $seen = [];

        foreach ($sourceVariants as $index => $candidate) {
            $sourceId = $this->stringOrNull($candidate['external_variant_id'] ?? null);

            if ($sourceId === null) {
                $errors[] = 'variant '.($index + 1).' has no external variant ID.';
                continue;
            }

            if (isset($seen[$sourceId])) {
                $errors[] = 'duplicate source variant ID '.$sourceId.'.';
                continue;
            }

            $seen[$sourceId] = true;
            $variantPrice = $this->numericOrNull($candidate['price_gross_amount'] ?? null) ?? $productPrice;

            if ($variantPrice === null || $variantPrice <= 0) {
                $errors[] = 'variant '.$sourceId.' has no positive gross price.';
            }

            $attributes = [];

            foreach (($candidate['attributes'] ?? []) as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }

                $code = $this->attributeCode($attribute);
                $value = $this->stringOrNull($attribute['value'] ?? null);
                $valueLabel = $this->stringOrNull($attribute['value_label'] ?? null) ?: $value;

                if ($code === '' || $value === null || $valueLabel === null) {
                    continue;
                }

                $attributes[] = [
                    'code' => $code,
                    'label' => $this->stringOrNull($attribute['label'] ?? null) ?: $this->humanize($code),
                    'value' => $value,
                    'value_label' => $valueLabel,
                ];
            }

            if ($attributes === []) {
                $errors[] = 'variant '.$sourceId.' has no concrete attributes.';
            }

            $active = ! array_key_exists('active', $candidate) || (bool) $candidate['active'];
            $visible = ! array_key_exists('visible', $candidate) || (bool) $candidate['visible'];
            $purchasable = ! array_key_exists('purchasable', $candidate) || (bool) $candidate['purchasable'];

            if (! $active || ! $visible || ! $purchasable) {
                $reviewItems[] = 'variant '.$sourceId.' is not fully active/visible/purchasable at source.';
            }

            $grossMinor = $variantPrice !== null ? $this->moneyToMinorUnits($variantPrice) : null;
            $plannedExternalId = $externalProductId !== null
                ? 'zamst-'.$externalProductId.'-'.$sourceId
                : 'zamst-unknown-'.$sourceId;

            $mapped[] = [
                'source_external_variant_id' => $sourceId,
                'external_variant_id' => $plannedExternalId,
                'sku' => $this->stringOrNull($candidate['sku'] ?? null)
                    ?: ($externalProductId !== null ? 'ZAMST-'.$externalProductId.'-'.$sourceId : 'ZAMST-'.$sourceId),
                'status' => 'draft',
                'is_default' => $mapped === [],
                'price_gross_minor' => $grossMinor,
                'price_net_minor' => $grossMinor !== null ? $this->netFromGross($grossMinor, $vatRate) : null,
                'currency' => mb_strtoupper($this->stringOrNull($candidate['currency'] ?? null) ?? $currency),
                'vat_rate' => $vatRate,
                'stock_status' => $this->availability($candidate['availability'] ?? $productAvailability),
                'attributes' => $attributes,
                'image' => $this->image($candidate['image'] ?? null),
                'source_active' => $active,
                'source_visible' => $visible,
                'source_purchasable' => $purchasable,
            ];
        }

        return $mapped;
    }

    /** @return list<array<string, mixed>> */
    private function categories(array $scraped): array
    {
        $paths = [];

        foreach (($scraped['source_category_paths'] ?? []) as $path) {
            $normalized = $this->stringList($path);

            if ($normalized !== []) {
                $paths[implode("\0", $normalized)] = $normalized;
            }
        }

        if ($paths === []) {
            foreach (($scraped['categories'] ?? []) as $category) {
                $name = $this->stringOrNull($category);

                if ($name !== null) {
                    $paths[$name] = [$name];
                }
            }
        }

        $preferredByLeaf = [];

        foreach ($paths as $path) {
            $leaf = mb_strtolower((string) end($path));

            if (! isset($preferredByLeaf[$leaf]) || count($path) > count($preferredByLeaf[$leaf])) {
                $preferredByLeaf[$leaf] = $path;
            }
        }

        $preferredPaths = array_values($preferredByLeaf);
        $primaryName = mb_strtolower($this->stringOrNull($scraped['category'] ?? null) ?? '');
        $primaryIndex = 0;

        foreach ($preferredPaths as $index => $path) {
            if ($primaryName !== '' && mb_strtolower((string) end($path)) === $primaryName) {
                $primaryIndex = $index;
                break;
            }
        }

        return array_values(array_map(
            static fn (array $path, int $index): array => [
                'path' => $path,
                'path_label' => implode(' > ', $path),
                'is_primary' => $index === $primaryIndex,
            ],
            $preferredPaths,
            array_keys($preferredPaths),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function images(array $scraped): array
    {
        $galleryUrls = [];
        $contentUrls = [];

        foreach (($scraped['gallery_images'] ?? []) as $image) {
            $mapped = $this->image($image);

            if ($mapped !== null) {
                $galleryUrls[$mapped['source_url']] = true;
            }
        }

        foreach (($scraped['content_images'] ?? []) as $image) {
            $mapped = $this->image($image);

            if ($mapped !== null) {
                $contentUrls[$mapped['source_url']] = true;
            }
        }

        $images = [];

        foreach (($scraped['images'] ?? []) as $image) {
            $mapped = $this->image($image);

            if ($mapped === null || isset($images[$mapped['source_url']])) {
                continue;
            }

            $url = $mapped['source_url'];
            $mapped['role'] = isset($galleryUrls[$url]) ? 'gallery' : (isset($contentUrls[$url]) ? 'content' : 'product');
            $mapped['is_main'] = $images === [];
            $images[$url] = $mapped;
        }

        return array_values($images);
    }

    /** @return array<string, mixed>|null */
    private function image(mixed $image): ?array
    {
        if (is_string($image)) {
            $url = $this->safeHttpUrl($image);

            return $url !== null ? ['source_url' => $url, 'alt' => null, 'title' => null] : null;
        }

        if (! is_array($image)) {
            return null;
        }

        $url = $this->safeHttpUrl($image['url'] ?? $image['source_url'] ?? null);

        if ($url === null) {
            return null;
        }

        return [
            'source_url' => $url,
            'alt' => $this->stringOrNull($image['alt'] ?? null),
            'title' => $this->stringOrNull($image['title'] ?? null),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function downloads(mixed $downloads): array
    {
        if (! is_array($downloads)) {
            return [];
        }

        $mapped = [];

        foreach ($downloads as $download) {
            if (! is_array($download)) {
                continue;
            }

            $url = $this->safeHttpUrl($download['url'] ?? null);

            if ($url === null || isset($mapped[$url])) {
                continue;
            }

            $mapped[$url] = [
                'source_url' => $url,
                'label' => $this->stringOrNull($download['label'] ?? $download['name'] ?? null),
                'extension' => $this->stringOrNull($download['extension'] ?? null)
                    ?: mb_strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)),
                'planned_handling' => 'preserve_as_product_content_link',
            ];
        }

        return array_values($mapped);
    }

    /** @return list<array<string, mixed>> */
    private function videos(mixed $videos): array
    {
        if (! is_array($videos)) {
            return [];
        }

        $mapped = [];

        foreach ($videos as $video) {
            $url = is_array($video)
                ? $this->safeHttpUrl($video['url'] ?? null)
                : $this->safeHttpUrl($video);

            if ($url === null || ! $this->isProductVideoUrl($url) || isset($mapped[$url])) {
                continue;
            }

            $mapped[$url] = [
                'source_url' => $url,
                'label' => is_array($video) ? $this->stringOrNull($video['label'] ?? $video['title'] ?? null) : null,
                'planned_handling' => 'preserve_as_product_content_link',
            ];
        }

        return array_values($mapped);
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

    private function safeHttpUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function paragraphHtml(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return '<p>'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
    }

    private function availability(mixed $value): string
    {
        $normalized = Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        if (in_array($normalized, ['out_of_stock', 'unavailable', 'sold_out', 'not_available'], true)
            || Str::of($normalized)->contains(['brak', 'niedostepn', 'wyprzedan'])) {
            return 'out_of_stock';
        }

        if (Str::of($normalized)->contains(['preorder', 'pre_order', 'na_zamowienie'])) {
            return 'preorder';
        }

        return 'in_stock';
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $string = $this->stringOrNull($item);

            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    private function attributeCode(array $attribute): string
    {
        $candidate = $this->stringOrNull($attribute['code'] ?? null)
            ?: $this->stringOrNull($attribute['label'] ?? null)
                ?: '';
        $code = Str::of($candidate)->lower()->ascii()->slug('-')->value();

        return $code !== '' ? $code : 'attribute';
    }

    private function humanize(string $value): string
    {
        return Str::of($value)->replace(['-', '_'], ' ')->squish()->title()->value();
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));
        $slug = $segments !== [] ? end($segments) : null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function moneyToMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function netFromGross(int $grossMinor, int $vatRate): int
    {
        return (int) round($grossMinor / (1 + ($vatRate / 100)));
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function arrayCount(mixed $value): int
    {
        return is_array($value) ? count($value) : 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
