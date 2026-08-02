<?php

declare(strict_types=1);

namespace App\Services\Novicare;

use Closure;
use DOMElement;
use DOMNode;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class NovicareProductScraper
{
    private const CANONICAL_HOST = 'novicare.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 500;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 2000;

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

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 2000): self
    {
        $this->maxAttempts = max(1, $attempts);
        $this->retryDelayMilliseconds = max(0, $retryDelayMilliseconds);

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
        $failed = [];
        $warnings = [];
        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;

        $this->emit('Fetching Novicare product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch Novicare product page.';

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
        $canonicalUrl = $this->canonicalUrl($crawler, $sourceUrl);
        $seoTitle = $this->normalizeText($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $name = $this->extractName($crawler, $seoTitle);
        $categoryData = $this->extractCategories($crawler, $productLinkContext, $name);
        $sectionData = $this->extractSectionData($crawler, $sourceUrl, $name);
        $sizeTable = $sectionData['size_table'];
        $colorTable = $sectionData['color_table'];
        $productCode = $this->extractProductCode($name, $productLinkContext);
        $externalProductId = $this->externalProductId($canonicalUrl, $productLinkContext);
        $variantCandidates = $this->variantCandidates(
            $canonicalUrl,
            $productCode,
            $sizeTable,
            $colorTable,
        );

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($sectionData['description_items'] === []) {
            $warnings[] = 'Product description was not found.';
        }

        if ($sectionData['images'] === []) {
            $warnings[] = 'Product images were not found.';
        }

        if ($sizeTable === null && $colorTable === null) {
            $warnings[] = 'Product size table was not found.';
        }

        return [
            'source' => 'novicare',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $externalProductId,
            'slug' => $this->slugFromUrl($canonicalUrl),
            'name' => $name,
            'product_code' => $productCode,
            'brand' => null,
            'category' => $categoryData['category'],
            'categories' => $categoryData['categories'],
            'category_paths' => $categoryData['category_paths'],
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'short_description' => $this->shortDescription(
                $seoDescription,
                $sectionData['description_items'],
            ),
            'description' => implode("\n", $sectionData['description_items']),
            'description_html' => $this->listHtml($sectionData['description_items']),
            'description_items' => $sectionData['description_items'],
            'indications' => $sectionData['indications'],
            'indications_html' => $this->listHtml($sectionData['indications']),
            'size_table' => $sizeTable,
            'color_table' => $colorTable,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => null,
            'ean' => null,
            'attributes' => $this->attributes($productCode, $sizeTable, $colorTable),
            'images' => $sectionData['images'],
            'detail_images' => $sectionData['detail_images'],
            'fitting_images' => $sectionData['fitting_images'],
            'related_products' => $sectionData['related_products'],
            'variant_candidates' => $variantCandidates,
            'is_medical_device' => true,
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ];
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = rawurldecode((string) parse_url($normalized, PHP_URL_PATH));

        if (preg_match('#^/produkty/[^/]+/[^/]+/$#u', $path) !== 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @param  array<string, string>  $failed
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function emptyResult(
        string $sourceUrl,
        ?array $productLinkContext,
        array $failed,
        array $warnings,
    ): array {
        $categoryData = $this->categoriesFromContext($productLinkContext);
        $canonicalUrl = $this->normalizeProductUrl($sourceUrl) ?? $sourceUrl;

        return [
            'source' => 'novicare',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $this->externalProductId($canonicalUrl, $productLinkContext),
            'slug' => $this->slugFromUrl($canonicalUrl),
            'name' => '',
            'product_code' => $this->contextString($productLinkContext, 'product_code'),
            'brand' => null,
            'category' => $categoryData['category'],
            'categories' => $categoryData['categories'],
            'category_paths' => $categoryData['category_paths'],
            'seo_title' => null,
            'seo_description' => null,
            'short_description' => null,
            'description' => '',
            'description_html' => null,
            'description_items' => [],
            'indications' => [],
            'indications_html' => null,
            'size_table' => null,
            'color_table' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => null,
            'ean' => null,
            'attributes' => [],
            'images' => [],
            'detail_images' => [],
            'fitting_images' => [],
            'related_products' => [],
            'variant_candidates' => [],
            'is_medical_device' => true,
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ];
    }

    private function canonicalUrl(Crawler $crawler, string $sourceUrl): string
    {
        $canonical = $this->firstAttr($crawler, 'link[rel="canonical"][href]', 'href');

        return is_string($canonical)
            ? ($this->normalizeProductUrl($canonical, $sourceUrl) ?? $sourceUrl)
            : $sourceUrl;
    }

    private function extractName(Crawler $crawler, string $seoTitle): string
    {
        $ogTitle = $this->firstMetaPropertyContent($crawler, 'og:title');

        foreach ([$ogTitle, $seoTitle] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = preg_replace(
                '/\s+-\s+NOVICARE\b.*$/iu',
                '',
                $this->normalizeText($candidate),
            ) ?? $candidate;

            if ($candidate !== '') {
                return $candidate;
            }
        }

        foreach (['main h1', 'main h2', 'article h1', 'article h2'] as $selector) {
            $name = $this->normalizeText($crawler->filter($selector)->first()->text(''));

            if ($name !== '' && ! $this->isSectionHeading($name)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @return array{category: string|null, categories: array<int, string>, category_paths: array<int, array<int, string>>}
     */
    private function extractCategories(Crawler $crawler, ?array $productLinkContext, string $name): array
    {
        $fromContext = $this->categoriesFromContext($productLinkContext);

        if ($fromContext['categories'] !== []) {
            return $fromContext;
        }

        $categories = [];

        foreach ([
            '.yoast-breadcrumb a',
            '.kadence-breadcrumbs a',
            '.breadcrumbs a',
            'nav[aria-label="breadcrumb"] a',
            'main a[href^="/produkty/"]',
        ] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$categories, $name): void {
                    $label = $this->normalizeText($node->text(''));
                    $href = $this->firstNodeAttr($node, 'href');

                    if ($label === '' || $label === $name || $label === 'Produkty' || $label === 'Strona główna') {
                        return;
                    }

                    if (! is_string($href) || preg_match('#^/?produkty/[^/]+/?$#u', $href) !== 1) {
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

        $names = array_keys($categories);

        return [
            'category' => $names === [] ? null : $names[array_key_last($names)],
            'categories' => $names,
            'category_paths' => $names === [] ? [] : [$names],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     * @return array{category: string|null, categories: array<int, string>, category_paths: array<int, array<int, string>>}
     */
    private function categoriesFromContext(?array $productLinkContext): array
    {
        $paths = [];

        if (is_array($productLinkContext['category_paths'] ?? null)) {
            foreach ($productLinkContext['category_paths'] as $path) {
                if (! is_array($path)) {
                    continue;
                }

                $normalized = array_values(array_filter(array_map(
                    fn (mixed $value): string => is_scalar($value) ? $this->normalizeText((string) $value) : '',
                    $path,
                )));

                if ($normalized !== []) {
                    $paths[] = $normalized;
                }
            }
        }

        $categories = [];

        foreach ($paths as $path) {
            foreach ($path as $category) {
                $categories[$category] = true;
            }
        }

        $category = null;

        if ($paths !== []) {
            $lastPath = $paths[array_key_last($paths)];
            $category = $lastPath[array_key_last($lastPath)] ?? null;
        }

        return [
            'category' => $category,
            'categories' => array_keys($categories),
            'category_paths' => $paths,
        ];
    }

    /**
     * @return array{
     *     description_items: array<int, string>,
     *     indications: array<int, string>,
     *     size_table: array<string, mixed>|null,
     *     color_table: array<string, mixed>|null,
     *     images: array<int, array<string, mixed>>,
     *     detail_images: array<int, array<string, mixed>>,
     *     fitting_images: array<int, array<string, mixed>>,
     *     related_products: array<int, array<string, mixed>>
     * }
     */
    private function extractSectionData(Crawler $crawler, string $baseUrl, string $productName): array
    {
        $root = $crawler->filter('main#main, main, article')->first()->getNode(0);

        if (! $root instanceof DOMElement) {
            return [
                'description_items' => [],
                'indications' => [],
                'size_table' => null,
                'color_table' => null,
                'images' => [],
                'detail_images' => [],
                'fitting_images' => [],
                'related_products' => [],
            ];
        }

        $currentSection = null;
        $descriptionItems = [];
        $indications = [];
        $sizeTable = null;
        $colorTable = null;
        $images = [];
        $relatedProducts = [];
        $position = 0;

        foreach ($this->descendantElements($root) as $element) {
            $tag = mb_strtolower($element->tagName);

            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $heading = $this->normalizeText($element->textContent);
                $currentSection = $this->sectionKey($heading, $productName);

                continue;
            }

            if ($currentSection === 'description' && $this->hasClass($element, 'kt-svg-icon-list-text')) {
                $this->appendUnique($descriptionItems, $element->textContent);

                continue;
            }

            if ($currentSection === 'indications' && $this->hasClass($element, 'kt-svg-icon-list-text')) {
                $this->appendUnique($indications, $element->textContent);

                continue;
            }

            if ($tag === 'p' && in_array($currentSection, ['description', 'indications'], true)) {
                if ($this->hasAncestorClass($element, 'kt-infobox-textcontent')) {
                    continue;
                }

                if ($currentSection === 'description') {
                    $this->appendUnique($descriptionItems, $element->textContent);
                } else {
                    $this->appendUnique($indications, $element->textContent);
                }

                continue;
            }

            if ($tag === 'table' && $currentSection === 'sizes' && $sizeTable === null) {
                $sizeTable = $this->parseSizeTable($element);

                continue;
            }

            if ($tag === 'table' && $currentSection === 'colors' && $colorTable === null) {
                $colorTable = $this->parseColorTable($element);

                continue;
            }

            if ($tag === 'img') {
                $type = $this->imageType($currentSection, $images);

                if ($type === null) {
                    continue;
                }

                $image = $this->imagePayload($element, $baseUrl, $type, $position, $productName);

                if ($image === null || isset($images[$image['url']])) {
                    continue;
                }

                $images[$image['url']] = $image;
                $position++;

                continue;
            }

            if ($tag === 'a' && $currentSection === 'related') {
                $href = $this->normalizeProductUrl($element->getAttribute('href'), $baseUrl);

                if ($href === null || isset($relatedProducts[$href])) {
                    continue;
                }

                $label = $this->normalizeRelatedProductLabel($element->textContent);

                if ($label === '') {
                    continue;
                }

                $relatedProducts[$href] = [
                    'url' => $href,
                    'name' => $label,
                    'product_code' => $this->extractProductCode($label, null),
                ];
            }
        }

        $imageRecords = array_values($images);

        return [
            'description_items' => array_values($descriptionItems),
            'indications' => array_values($indications),
            'size_table' => $sizeTable,
            'color_table' => $colorTable,
            'images' => $imageRecords,
            'detail_images' => array_values(array_filter(
                $imageRecords,
                static fn (array $image): bool => $image['type'] === 'detail',
            )),
            'fitting_images' => array_values(array_filter(
                $imageRecords,
                static fn (array $image): bool => $image['type'] === 'fitting',
            )),
            'related_products' => array_values($relatedProducts),
        ];
    }

    /**
     * @return array<int, DOMElement>
     */
    private function descendantElements(DOMElement $root): array
    {
        $elements = [];
        $this->collectDescendantElements($root, $elements);

        return $elements;
    }

    /**
     * @param  array<int, DOMElement>  $elements
     */
    private function collectDescendantElements(DOMNode $node, array &$elements): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $elements[] = $child;
            $this->collectDescendantElements($child, $elements);
        }
    }

    private function sectionKey(string $heading, string $productName): ?string
    {
        $normalized = mb_strtolower($this->normalizeText($heading));

        if ($normalized === '' || $heading === $productName) {
            return 'product';
        }

        return match (true) {
            $normalized === 'opis' => 'description',
            $normalized === 'wskazania' => 'indications',
            str_starts_with($normalized, 'dostępne rozmiary'),
            str_starts_with($normalized, 'dostepne rozmiary') => 'sizes',
            str_starts_with($normalized, 'dostępne kolory'),
            str_starts_with($normalized, 'dostepne kolory') => 'colors',
            str_starts_with($normalized, 'detale produktu') => 'details',
            str_starts_with($normalized, 'sposób zakładania'),
            str_starts_with($normalized, 'sposob zakladania') => 'fitting',
            str_starts_with($normalized, 'powiązane produkty'),
            str_starts_with($normalized, 'powiazane produkty') => 'related',
            str_starts_with($normalized, 'masz jakieś pytania'),
            str_starts_with($normalized, 'masz jakies pytania') => 'contact',
            default => 'other',
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     */
    private function imageType(?string $section, array $images): ?string
    {
        if (in_array($section, ['related', 'contact'], true)) {
            return null;
        }

        if ($section === 'details') {
            return 'detail';
        }

        if ($section === 'fitting') {
            return 'fitting';
        }

        if ($images === [] && in_array($section, ['product', 'other', null], true)) {
            return 'main';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imagePayload(
        DOMElement $image,
        string $baseUrl,
        string $type,
        int $position,
        string $productName,
    ): ?array {
        $candidate = '';

        foreach (['data-full-image', 'data-light-image', 'src'] as $attribute) {
            $value = trim($image->getAttribute($attribute));

            if ($value !== '') {
                $candidate = $value;
                break;
            }
        }

        $url = $this->normalizeAssetUrl($candidate, $baseUrl);

        if ($url === null || ! str_contains($url, '/wp-content/uploads/')) {
            return null;
        }

        $alt = $this->normalizeText($image->getAttribute('alt'));

        return [
            'type' => $type,
            'url' => $url,
            'source_url' => $url,
            'alt' => $alt !== '' ? $alt : $productName,
            'position' => $position,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseSizeTable(DOMElement $table): ?array
    {
        $crawler = new Crawler($table);
        $matrix = [];

        $crawler->filter('tr')->each(function (Crawler $row) use (&$matrix): void {
            $cells = [];

            $row->filter('th, td')->each(function (Crawler $cell) use (&$cells): void {
                $cells[] = $this->normalizeText($cell->text(''));
            });

            if ($cells !== []) {
                $matrix[] = $cells;
            }
        });

        if ($matrix === [] || count($matrix[0]) < 2) {
            return null;
        }

        $headers = $matrix[0];
        $sizes = array_values(array_filter(array_slice($headers, 1), static fn (string $value): bool => $value !== ''));

        if ($sizes === []) {
            return null;
        }

        $rows = [];

        foreach (array_slice($matrix, 1) as $row) {
            $label = $row[0] ?? '';
            $values = array_slice($row, 1);
            $mappedValues = [];

            foreach ($sizes as $index => $size) {
                $mappedValues[$size] = $values[$index] ?? null;
            }

            $rows[] = [
                'label' => $label,
                'values' => $mappedValues,
            ];
        }

        return [
            'header_label' => $headers[0],
            'sizes' => $sizes,
            'rows' => $rows,
            'matrix' => $matrix,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseColorTable(DOMElement $table): ?array
    {
        $tableData = $this->parseSizeTable($table);

        if (! is_array($tableData)) {
            return null;
        }

        $models = array_values(array_filter(
            $tableData['sizes'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        $colorRow = collect($tableData['rows'] ?? [])->first(
            fn (mixed $row): bool => is_array($row)
                && in_array(mb_strtolower($this->normalizeText((string) ($row['label'] ?? ''))), ['kolor', 'color', 'colour'], true),
        );

        if ($models === [] || ! is_array($colorRow)) {
            return null;
        }

        $options = [];

        foreach ($models as $modelCode) {
            $color = is_array($colorRow['values'] ?? null)
                ? ($colorRow['values'][$modelCode] ?? null)
                : null;

            if (! is_string($color) || trim($color) === '') {
                continue;
            }

            $options[] = [
                'model_code' => $this->normalizeText($modelCode),
                'color' => $this->normalizeText($color),
            ];
        }

        if ($options === []) {
            return null;
        }

        return [
            'header_label' => $tableData['header_label'],
            'models' => $models,
            'options' => $options,
            'rows' => $tableData['rows'],
            'matrix' => $tableData['matrix'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sizeTable
     * @param  array<string, mixed>|null  $colorTable
     * @return array<int, array<string, mixed>>
     */
    private function variantCandidates(
        string $canonicalUrl,
        ?string $productCode,
        ?array $sizeTable,
        ?array $colorTable,
    ): array {
        if (is_array($sizeTable) && is_array($sizeTable['sizes'] ?? null)) {
            $variants = [];

            foreach ($sizeTable['sizes'] as $size) {
                if (! is_string($size) || trim($size) === '') {
                    continue;
                }

                $measurements = [];

                foreach ($sizeTable['rows'] ?? [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $label = $this->normalizeText((string) ($row['label'] ?? ''));
                    $value = is_array($row['values'] ?? null) ? ($row['values'][$size] ?? null) : null;

                    if ($label !== '' && is_string($value) && trim($value) !== '') {
                        $measurements[$label] = $this->normalizeText($value);
                    }
                }

                $firstMeasurementLabel = $measurements === [] ? null : array_key_first($measurements);
                $firstMeasurement = $firstMeasurementLabel === null ? null : $measurements[$firstMeasurementLabel];

                $variants[] = [
                    'external_variant_id' => hash('sha256', $canonicalUrl.'|size|'.mb_strtolower($size)),
                    'sku' => null,
                    'product_code' => $productCode,
                    'size' => $size,
                    'color' => null,
                    'model_code' => null,
                    'name' => $size,
                    'option_values' => [
                        [
                            'attribute' => 'Rozmiar',
                            'value' => $size,
                        ],
                    ],
                    'measurements' => $measurements,
                    'measurement_label' => $firstMeasurementLabel,
                    'measurement' => $firstMeasurement,
                    'price_gross_amount' => null,
                    'currency' => 'PLN',
                    'availability' => 'unknown',
                    'stock_quantity' => null,
                ];
            }

            return $variants;
        }

        if (! is_array($colorTable) || ! is_array($colorTable['options'] ?? null)) {
            return [];
        }

        $variants = [];

        foreach ($colorTable['options'] as $option) {
            if (! is_array($option)) {
                continue;
            }

            $modelCode = $this->normalizeText((string) ($option['model_code'] ?? ''));
            $color = $this->normalizeText((string) ($option['color'] ?? ''));

            if ($modelCode === '' || $color === '') {
                continue;
            }

            $variants[] = [
                'external_variant_id' => hash(
                    'sha256',
                    $canonicalUrl.'|model|'.mb_strtolower($modelCode).'|color|'.mb_strtolower($color),
                ),
                'sku' => null,
                'product_code' => $modelCode,
                'size' => null,
                'color' => $color,
                'model_code' => $modelCode,
                'name' => $modelCode.' – '.$color,
                'option_values' => [
                    [
                        'attribute' => 'Kolor',
                        'value' => $color,
                    ],
                ],
                'measurements' => [],
                'measurement_label' => null,
                'measurement' => null,
                'price_gross_amount' => null,
                'currency' => 'PLN',
                'availability' => 'unknown',
                'stock_quantity' => null,
            ];
        }

        return $variants;
    }

    /**
     * @param  array<string, mixed>|null  $sizeTable
     * @return array<int, array{code: string, label: string, value: string, slug: string|null}>
     */
    private function attributes(?string $productCode, ?array $sizeTable, ?array $colorTable): array
    {
        $attributes = [];

        if ($productCode !== null) {
            $attributes[] = [
                'code' => 'kod-produktu',
                'label' => 'Kod produktu',
                'value' => $productCode,
                'slug' => $this->slugify($productCode),
            ];
        }

        if (is_array($sizeTable['sizes'] ?? null) && $sizeTable['sizes'] !== []) {
            $sizes = implode(', ', array_map('strval', $sizeTable['sizes']));
            $attributes[] = [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => $sizes,
                'slug' => $this->slugify($sizes),
            ];
        }

        if (is_array($colorTable['options'] ?? null) && $colorTable['options'] !== []) {
            $models = implode(', ', array_column($colorTable['options'], 'model_code'));
            $colors = implode(', ', array_column($colorTable['options'], 'color'));

            $attributes[] = [
                'code' => 'model',
                'label' => 'Model',
                'value' => $models,
                'slug' => $this->slugify($models),
            ];

            $attributes[] = [
                'code' => 'kolor',
                'label' => 'Kolor',
                'value' => $colors,
                'slug' => $this->slugify($colors),
            ];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function extractProductCode(string $name, ?array $productLinkContext): ?string
    {
        foreach ([
            '/\b([A-Z]{1,6}[A-Z0-9]*(?:-[A-Z0-9]+)+(?:\s+BOA)?)\s*(?:\(?UNI\)?)?\s*$/u',
            '/\b([A-Z]{2,6}[0-9]{1,4})\s*(?:\(?UNI\)?)?\s*$/u',
            '/\b([0-9]{3,6})\s*$/u',
        ] as $pattern) {
            if (preg_match($pattern, trim($name), $matches) === 1) {
                return trim($matches[1]);
            }
        }

        $contextCode = $this->contextString($productLinkContext, 'product_code');

        if ($contextCode === null) {
            return null;
        }

        return preg_replace('/(?<=[0-9])UNI$/u', '', $contextCode) ?: $contextCode;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function externalProductId(string $canonicalUrl, ?array $productLinkContext): string
    {
        $contextExternalId = $this->contextString($productLinkContext, 'external_id');

        return $contextExternalId ?? hash('sha256', $canonicalUrl);
    }

    /**
     * @param  array<int, string>  $descriptionItems
     */
    private function shortDescription(?string $seoDescription, array $descriptionItems): ?string
    {
        if (is_string($seoDescription) && trim($seoDescription) !== '') {
            return $this->normalizeText($seoDescription);
        }

        return $descriptionItems[0] ?? null;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function listHtml(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        return '<ul>'.implode('', array_map(
            static fn (string $item): string => '<li>'.htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>',
            $items,
        )).'</ul>';
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
                    ->withOptions(['verify' => $this->verifyTls])
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

    private function normalizeUrl(string $candidate, ?string $baseUrl = null): ?string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with(mb_strtolower($candidate), 'javascript:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            $basePath = (string) parse_url($baseUrl ?? 'https://'.self::CANONICAL_HOST.'/', PHP_URL_PATH);
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

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));

        if ($path !== '/' && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return 'https://'.self::CANONICAL_HOST.$path;
    }

    private function normalizeAssetUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            $candidate = rtrim($baseUrl, '/').'/'.ltrim($candidate, '/');
        }

        $parts = parse_url($candidate);

        if (! is_array($parts)) {
            return null;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, [self::CANONICAL_HOST, 'www.'.self::CANONICAL_HOST], true)) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));

        return 'https://'.self::CANONICAL_HOST.$path;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = rawurlencode(rawurldecode($segment));
        }

        return '/'.implode('/', $segments);
    }

    private function slugFromUrl(string $url): ?string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('#^/produkty/[^/]+/([^/]+)/$#u', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        $value = $this->firstAttr($crawler, 'meta[name="'.$name.'"][content]', 'content');

        return is_string($value) && trim($value) !== '' ? $this->normalizeText($value) : null;
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        $value = $this->firstAttr($crawler, 'meta[property="'.$property.'"][content]', 'content');

        return is_string($value) && trim($value) !== '' ? $this->normalizeText($value) : null;
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

    private function firstNodeAttr(Crawler $crawler, string $attribute): ?string
    {
        try {
            $value = $crawler->attr($attribute);

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalizeRelatedProductLabel(string $value): string
    {
        $label = $this->normalizeText($value);
        $productCode = $this->extractProductCode($label, null);

        if ($productCode === null || ! str_starts_with($label, $productCode)) {
            return $label;
        }

        $suffix = mb_substr($label, mb_strlen($productCode));

        if ($suffix === '' || preg_match('/^\s/u', $suffix) === 1) {
            return $label;
        }

        return $productCode.' '.$suffix;
    }

    private function isSectionHeading(string $heading): bool
    {
        return in_array(mb_strtolower($heading), [
            'opis',
            'wskazania',
            'dostępne rozmiary',
            'detale produktu',
            'sposób zakładania',
            'powiązane produkty',
        ], true);
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        $classes = preg_split('/\s+/u', trim($element->getAttribute('class'))) ?: [];

        return in_array($class, $classes, true);
    }

    private function hasAncestorClass(DOMElement $element, string $class): bool
    {
        $parent = $element->parentNode;

        while ($parent instanceof DOMElement) {
            if ($this->hasClass($parent, $class)) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * @param  array<string, string>  $items
     */
    private function appendUnique(array &$items, string $value): void
    {
        $value = $this->normalizeText($value);

        if ($value !== '') {
            $items[$value] = $value;
        }
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function contextString(?array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function slugify(string $value): ?string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = is_string($ascii) ? $ascii : $value;
        $slug = mb_strtolower($ascii);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : null;
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopNovicareProductCrawler/1.0)',
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
