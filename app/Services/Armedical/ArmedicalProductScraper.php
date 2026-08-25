<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ArmedicalProductScraper
{
    private ?Closure $progressCallback = null;

    public function __construct(
        private readonly ArmedicalHttpClient $http,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->http->withTimeout($seconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->http->withMaxAttempts($attempts, $retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->http->withRequestDelayMilliseconds($milliseconds);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $context = null): array
    {
        $url = ArmedicalUrl::product($url) ?? $url;
        $this->emit('Fetching ARmedical product: '.$url);
        $response = $this->http->fetch($url);

        if (! is_string($response['body'])) {
            return $this->emptyResult($url, $context, [
                $url => (string) ($response['error'] ?? 'Unknown HTTP error'),
            ]);
        }

        return $this->extract($response['body'], $url, $context);
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public function extract(string $html, string $url, ?array $context = null): array
    {
        $sourceUrl = ArmedicalUrl::product($url) ?? $url;
        $failed = [];
        $warnings = [];

        try {
            $crawler = new Crawler($html, $sourceUrl);
        } catch (Throwable $exception) {
            return $this->emptyResult($sourceUrl, $context, [$sourceUrl => $exception->getMessage()]);
        }

        $canonicalUrl = $this->extractCanonicalUrl($crawler, $sourceUrl);
        $externalProductId = ArmedicalUrl::externalProductId($canonicalUrl ?? $sourceUrl);
        $heading = $this->firstText($crawler, ['h1', 'main h1', 'article h1']);
        [$catalogueNumber, $nameFromHeading] = $this->splitCatalogueNumberAndName($heading);
        $name = $nameFromHeading ?: $this->contextString($context, 'name') ?: $this->titleName($crawler);
        $catalogueNumber ??= $this->contextString($context, 'catalogue_number');
        $descriptionHtml = $this->extractDescriptionHtml($crawler, $name);
        $description = $this->normalizeText(strip_tags((string) $descriptionHtml));
        $categories = $this->extractCategories($crawler, $name, $context);
        $specifications = $this->extractSpecifications($crawler, (string) $descriptionHtml);
        $images = $this->extractImages($crawler, $canonicalUrl ?? $sourceUrl);
        $documents = $this->extractDocuments($crawler, $canonicalUrl ?? $sourceUrl);
        $sizeOptions = $this->extractSizeOptions($crawler, $catalogueNumber);
        $warnings = array_merge($warnings, $this->catalogueConsistencyWarnings($catalogueNumber, $sizeOptions));

        if ($catalogueNumber === null) {
            $warnings[] = 'Catalogue number was not found in the product heading.';
        }

        if ($descriptionHtml === null || $description === '') {
            $warnings[] = 'Product description was not found.';
        }

        if ($images === []) {
            $warnings[] = 'No product images were found.';
        }

        return [
            'source' => 'armedical',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl ?? $sourceUrl,
            'external_product_id' => $externalProductId,
            'slug' => ArmedicalUrl::productSlug($canonicalUrl ?? $sourceUrl),
            'catalogue_number' => $catalogueNumber,
            'sku' => $this->singleCatalogueNumber($catalogueNumber),
            'name' => $name,
            'brand' => 'ARmedical',
            'manufacturer' => 'ARMEDICAL Sp. z o.o.',
            'category' => $categories !== [] ? end($categories) : null,
            'categories' => $categories,
            'seo_title' => ($title = $this->firstText($crawler, ['title'])) !== '' ? $title : null,
            'seo_description' => $this->firstMetaContent($crawler, 'name', 'description'),
            'short_description' => $this->firstParagraph($descriptionHtml),
            'description' => $description,
            'description_html' => $descriptionHtml,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'technical_specifications' => $specifications,
            'attributes' => $this->specificationsToAttributes($specifications),
            'size_options' => $sizeOptions,
            'images' => $images,
            'documents' => $documents,
            'is_medical_device' => $this->isMedicalDevice($html),
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(string $url, ?array $context, array $failed): array
    {
        return [
            'source' => 'armedical',
            'source_url' => $url,
            'canonical_url' => null,
            'external_product_id' => ArmedicalUrl::externalProductId($url),
            'slug' => ArmedicalUrl::productSlug($url),
            'catalogue_number' => $this->contextString($context, 'catalogue_number'),
            'sku' => null,
            'name' => $this->contextString($context, 'name') ?? '',
            'brand' => 'ARmedical',
            'manufacturer' => 'ARMEDICAL Sp. z o.o.',
            'category' => null,
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
            'stock_quantity' => null,
            'technical_specifications' => [],
            'attributes' => [],
            'size_options' => [],
            'images' => [],
            'documents' => [],
            'is_medical_device' => false,
            'warnings' => [],
            'failed_urls' => $failed,
        ];
    }

    private function extractCanonicalUrl(Crawler $crawler, string $sourceUrl): ?string
    {
        try {
            $href = (string) $crawler->filter('link[rel="canonical"]')->first()->attr('href');
        } catch (Throwable) {
            return ArmedicalUrl::product($sourceUrl);
        }

        return ArmedicalUrl::product($href, $sourceUrl) ?? ArmedicalUrl::product($sourceUrl);
    }

    private function titleName(Crawler $crawler): string
    {
        $title = $this->firstText($crawler, ['title']);
        $title = preg_replace('/\s*[-|]\s*ARmedical.*$/iu', '', $title) ?? $title;
        [, $name] = $this->splitCatalogueNumberAndName($this->normalizeText($title));

        return $name ?: $this->normalizeText($title);
    }

    private function extractDescriptionHtml(Crawler $crawler, string $name): ?string
    {
        foreach ([
            '.entry-content',
            '.oferta-content',
            '.offer-content',
            '.product-content',
            'article .content',
            'article',
            'main',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
            } catch (Throwable) {
                continue;
            }

            if ($node->count() === 0) {
                continue;
            }

            $html = $node->html();

            if (! is_string($html) || mb_strlen($this->normalizeText(strip_tags($html))) < 40) {
                continue;
            }

            return $this->cleanDescriptionHtml($html, $name);
        }

        return null;
    }

    private function cleanDescriptionHtml(string $html, string $name): string
    {
        $html = preg_replace('#<(script|style|nav|header|footer|form|aside)\b[^>]*>.*?</\1>#isu', '', $html) ?? $html;
        $html = preg_replace('#<button\b[^>]*>.*?</button>#isu', '', $html) ?? $html;
        $html = $this->rewriteRelativeAttributes($html);

        if ($name !== '') {
            $html = preg_replace('#^\s*<h1\b[^>]*>.*?</h1>\s*#isu', '', $html) ?? $html;
        }

        return trim($html);
    }

    private function rewriteRelativeAttributes(string $html): string
    {
        return preg_replace_callback(
            '#\b(src|href)=(["\'])([^"\']+)\2#iu',
            function (array $matches): string {
                $attribute = $matches[1];
                $quote = $matches[2];
                $value = $matches[3];
                $absolute = $this->normalizeAssetUrl($value, ArmedicalUrl::BASE_URL);

                return $attribute.'='.$quote.($absolute ?? $value).$quote;
            },
            $html,
        ) ?? $html;
    }

    /** @return array<int, string> */
    private function extractCategories(Crawler $crawler, string $name, ?array $context): array
    {
        $categories = [];

        foreach (['.breadcrumbs a', '.breadcrumb a', '.yoast-breadcrumb a', 'nav[aria-label="breadcrumb"] a'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$categories, $name): void {
                    $label = $this->normalizeText((string) $node->text(''));

                    if ($label === '' || $this->isNonCategoryLabel($label, $name)) {
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

        if ($categories === [] && is_string($context['source_category_url'] ?? null)) {
            $slug = ArmedicalUrl::categorySlug($context['source_category_url']);

            if ($slug !== null) {
                $categories[str_replace('-', ' ', $slug)] = true;
            }
        }

        return array_keys($categories);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function extractSpecifications(Crawler $crawler, string $descriptionHtml): array
    {
        $specifications = [];

        try {
            $content = new Crawler($descriptionHtml);
        } catch (Throwable) {
            $content = $crawler;
        }

        try {
            $content->filter('table')->each(function (Crawler $table) use (&$specifications): void {
                if ($this->isSizeTable($table)) {
                    return;
                }

                $table->filter('tr')->each(function (Crawler $row) use (&$specifications): void {
                    $cells = $row->filter('th, td');

                    if ($cells->count() < 2) {
                        return;
                    }

                    $label = $this->normalizeText((string) $cells->eq(0)->text(''));
                    $value = $this->normalizeText((string) $cells->eq(1)->text(''));

                    // Some ARmedical layout tables put several "label: value" pairs into
                    // each cell. They are not key/value specification rows and should be
                    // parsed from their individual text fragments below instead.
                    if (str_contains($label, ':') || str_contains($value, ':')) {
                        return;
                    }

                    $this->addSpecification($specifications, $label, $value);
                });
            });
        } catch (Throwable) {
            // Continue with list/text extraction.
        }

        foreach (['.parametry-techniczne li', '.parameters li', '.specification li', 'li'] as $selector) {
            try {
                $content->filter($selector)->each(function (Crawler $node) use (&$specifications): void {
                    $text = $this->normalizeText((string) $node->text(''));

                    if (preg_match('/^([^:]{2,80}):\s*(.{1,250})$/u', $text, $matches) === 1) {
                        $this->addSpecification($specifications, $matches[1], $matches[2]);
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        foreach ([
            '.product-info p',
            '.additional-informations p',
            '.parametry-techniczne p',
            '.parameters p',
            '.specification p',
        ] as $selector) {
            try {
                $content->filter($selector)->each(function (Crawler $node) use (&$specifications): void {
                    $text = ltrim(
                        $this->normalizeText((string) $node->text('')),
                        " \t\n\r\0\x0B–—-",
                    );

                    if (preg_match('/^([^:]{2,80}):\s*(.{1,250})$/u', $text, $matches) === 1) {
                        $this->addSpecification($specifications, $matches[1], $matches[2]);
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        // ARmedical commonly renders the technical-parameter section as plain text or
        // visually split list content. Extract the known labels independently so a
        // layout wrapper cannot collapse multiple parameters into one pseudo-row.
        // The plain-text fallback is useful for ARmedical parameter sections that are
        // not represented as clean key/value markup. Recognised variant tables must be
        // removed first, otherwise labels such as "Wysokość:" inside a collar size
        // matrix are reintroduced as bogus technical specifications after the table
        // itself has already been classified as variant data.
        $plain = $this->normalizeText(strip_tags($this->withoutVariantTables($descriptionHtml)));

        if (preg_match_all(
            '/(?:^|\s)(szerokość(?: całkowita| uchwytów)?|wysokość(?: min\.| max\.| uchwyty górne| uchwyty dolne)?|waga|maksymalne obciążenie|długość|koła materiał|kolor|materiał)\s*:\s*(.+?)(?=\s+(?:szerokość(?: całkowita| uchwytów)?|wysokość(?: min\.| max\.| uchwyty górne| uchwyty dolne)?|waga|maksymalne obciążenie|długość|koła materiał|kolor|materiał)\s*:|$)/iu',
            $plain,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $this->addSpecification($specifications, $match[1], $match[2]);
            }
        }

        return array_values($specifications);
    }

    private function withoutVariantTables(string $html): string
    {
        return preg_replace_callback(
            '#<table\b[^>]*>.*?</table>#isu',
            function (array $matches): string {
                try {
                    $table = new Crawler($matches[0]);

                    return $this->isSizeTable($table) ? '' : $matches[0];
                } catch (Throwable) {
                    return $matches[0];
                }
            },
            $html,
        ) ?? $html;
    }

    /** @param array<string, array{label: string, value: string}> $specifications */
    private function addSpecification(array &$specifications, string $label, string $value): void
    {
        $label = $this->normalizeText($label);
        $value = $this->sanitizeSpecificationValue($value);

        if ($label === '' || $value === '' || mb_strlen($label) > 100 || mb_strlen($value) > 300) {
            return;
        }

        $key = ArmedicalUrl::slugify($label);

        if ($key === '' || isset($specifications[$key])) {
            return;
        }

        $specifications[$key] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    private function sanitizeSpecificationValue(string $value): string
    {
        $value = $this->normalizeText($value);
        $parts = preg_split(
            '/\s+(?=(?:Instrukcja obsługi|Dokumenty rejestrowe|Nasadka gumowa|Dostępn(?:y|e) kolor(?:y)?|Rozmiary|Kolory)\b)/iu',
            $value,
            2,
        );

        if (is_array($parts) && isset($parts[0])) {
            $value = $parts[0];
        }

        return trim($value, " \t\n\r\0\x0B–—-");
    }

    /** @param array<int, array{label: string, value: string}> $specifications */
    private function specificationsToAttributes(array $specifications): array
    {
        $attributes = [];

        foreach ($specifications as $specification) {
            $attributes[$specification['label']] = $specification['value'];
        }

        return $attributes;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function extractSizeOptions(Crawler $crawler, ?string $catalogueNumber = null): array
    {
        $sizes = [];

        try {
            $crawler->filter('table')->each(function (Crawler $table) use (&$sizes, $catalogueNumber): void {
                $options = $this->tableVariantOptions($table, $catalogueNumber);

                if ($options === null) {
                    return;
                }

                foreach ($options as $option) {
                    $sizes[$option['label'].'|'.$option['value']] = $option;
                }
            });
        } catch (Throwable) {
            return [];
        }

        return array_values($sizes);
    }

    private function isSizeTable(Crawler $table): bool
    {
        return $this->horizontalSizeMatrix($table) !== null
            || $this->collarHeightSizeMatrix($table) !== null
            || $this->modelComparisonOptions($table) !== null
            || $this->isWalkerSizeTable($table)
            || $this->columnSizeOptions($table) !== null
            || $this->directSizeRows($table) !== null
            || $this->wheelchairSizeMatrix($table) !== null;
    }

    /** @return array<int, array{label: string, value: string}>|null */
    private function tableVariantOptions(Crawler $table, ?string $catalogueNumber): ?array
    {
        foreach ([
            $this->horizontalSizeMatrix($table),
            $this->collarHeightSizeMatrix($table),
            $this->modelComparisonOptions($table),
            $this->walkerSizeMatrix($table, $catalogueNumber),
            $this->columnSizeOptions($table),
            $this->directSizeRows($table),
            $this->wheelchairSizeMatrix($table),
        ] as $options) {
            if ($options !== null && $options !== []) {
                return $options;
            }
        }

        return null;
    }

    /**
     * Some ARmedical products (for example AR-060 / AR-061) render sizes as
     * a horizontal matrix:
     *
     *   Rozmiar | 1     | 2         | 3
     *   Obwód   | <3,5  | 3,5÷4,0   | 4,0÷4,5
     *
     * In that layout each column after the first one is a distinct variant.
     * Treating the first row as a conventional table header loses every size
     * except the first value from the second row.
     *
     * @return array<int, array{label: string, value: string}>|null
     */
    private function horizontalSizeMatrix(Crawler $table): ?array
    {
        try {
            $rows = $table->filter('tr');

            for ($rowIndex = 0; $rowIndex < min(3, $rows->count()); $rowIndex++) {
                $headerCells = $rows->eq($rowIndex)->filter('th, td');

                if ($headerCells->count() < 3) {
                    continue;
                }

                $firstHeader = mb_strtolower(
                    trim($this->normalizeText((string) $headerCells->eq(0)->text('')), " \t\n\r\0\x0B:"),
                );

                if (! in_array($firstHeader, ['rozmiar', 'size'], true)) {
                    continue;
                }

                if ($rowIndex + 1 >= $rows->count()) {
                    continue;
                }

                $valueCells = $rows->eq($rowIndex + 1)->filter('th, td');

                if ($valueCells->count() !== $headerCells->count()) {
                    continue;
                }

                $descriptor = $this->normalizeText((string) $valueCells->eq(0)->text(''));

                if ($descriptor === '') {
                    continue;
                }

                $options = [];

                for ($cellIndex = 1; $cellIndex < $headerCells->count(); $cellIndex++) {
                    $label = $this->normalizeText((string) $headerCells->eq($cellIndex)->text(''));
                    $value = $this->normalizeText((string) $valueCells->eq($cellIndex)->text(''));

                    if ($label === '' || $value === '') {
                        continue;
                    }

                    $options[] = [
                        'label' => $label,
                        'value' => $value,
                    ];
                }

                if ($options !== []) {
                    return $options;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return array<int, array{label: string, value: string}>|null */
    private function collarHeightSizeMatrix(Crawler $table): ?array
    {
        try {
            $rows = $table->filter('tr');

            if ($rows->count() < 2) {
                return null;
            }

            $headers = $this->rowTexts($rows->eq(0));
            $heights = [];

            if (count($headers) < 2) {
                return null;
            }

            foreach ($headers as $header) {
                if (preg_match('/^wysokość\s*:\s*(.+)$/iu', $header, $matches) !== 1) {
                    return null;
                }

                $heights[] = $this->normalizeText($matches[1]);
            }

            $options = [];

            for ($rowIndex = 1; $rowIndex < $rows->count(); $rowIndex++) {
                $cells = $this->rowTexts($rows->eq($rowIndex));

                if (count($cells) < count($heights) * 2) {
                    continue;
                }

                foreach ($heights as $heightIndex => $height) {
                    $labelIndex = $heightIndex * 2;
                    $valueIndex = $labelIndex + 1;
                    $size = preg_replace('/^rozmiar\s+/iu', '', $cells[$labelIndex]) ?? $cells[$labelIndex];
                    $size = trim($this->normalizeText($size), " \t\n\r\0\x0B:");
                    $value = $this->normalizeText($cells[$valueIndex]);

                    if ($size === '' || $height === '' || $value === '') {
                        continue;
                    }

                    $options[] = [
                        'label' => $size.' / '.$height,
                        'value' => $value,
                    ];
                }
            }

            return $options !== [] ? $options : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array{label: string, value: string}>|null */
    private function modelComparisonOptions(Crawler $table): ?array
    {
        try {
            $rows = $table->filter('tr');

            if ($rows->count() < 2) {
                return null;
            }

            $headers = $this->rowTexts($rows->eq(0));

            if (count($headers) < 3 || mb_strtolower(trim($headers[0], " \t\n\r\0\x0B:")) !== 'parametr') {
                return null;
            }

            $models = [];

            foreach (array_slice($headers, 1) as $header) {
                $model = preg_replace('/^model\s+/iu', '', $header) ?? $header;
                $model = $this->normalizeText($model);

                if ($model === '') {
                    return null;
                }

                $models[] = $model;
            }

            $detailsByModel = array_fill(0, count($models), []);

            for ($rowIndex = 1; $rowIndex < $rows->count(); $rowIndex++) {
                $cells = $this->rowTexts($rows->eq($rowIndex));

                if (count($cells) < count($models) + 1) {
                    continue;
                }

                $parameter = trim($this->normalizeText($cells[0]), " \t\n\r\0\x0B:");
                $values = array_slice($cells, 1, count($models));
                $nonEmpty = array_values(array_filter($values, static fn (string $value): bool => trim($value) !== ''));

                if ($parameter === '' || $nonEmpty === [] || count(array_unique($nonEmpty)) < 2) {
                    continue;
                }

                foreach ($models as $modelIndex => $_model) {
                    $value = $this->normalizeText($values[$modelIndex] ?? '');

                    if ($value !== '') {
                        $detailsByModel[$modelIndex][] = $parameter.': '.$value;
                    }
                }
            }

            $options = [];

            foreach ($models as $modelIndex => $model) {
                if ($detailsByModel[$modelIndex] === []) {
                    continue;
                }

                $options[] = [
                    'label' => $model,
                    'value' => implode('; ', $detailsByModel[$modelIndex]),
                ];
            }

            return count($options) === count($models) ? $options : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isWalkerSizeTable(Crawler $table): bool
    {
        try {
            $rows = $table->filter('tr');

            if ($rows->count() < 3) {
                return false;
            }

            $headers = array_map(
                static fn (string $value): string => mb_strtolower($value),
                $this->rowTexts($rows->eq(0)),
            );

            if (! in_array('rozmiar buta', $headers, true)) {
                return false;
            }

            return count(array_filter(
                $this->rowTexts($rows->eq(1)),
                static fn (string $value): bool => preg_match('/^AR-\d+[A-Z]?$/iu', trim($value)) === 1,
            )) >= 2;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, array{label: string, value: string}>|null */
    private function walkerSizeMatrix(Crawler $table, ?string $catalogueNumber): ?array
    {
        if (! $this->isWalkerSizeTable($table) || $catalogueNumber === null) {
            return null;
        }

        try {
            $rows = $table->filter('tr');
            $models = array_values(array_filter(
                $this->rowTexts($rows->eq(1)),
                static fn (string $value): bool => preg_match('/^AR-\d+[A-Z]?$/iu', trim($value)) === 1,
            ));
            $modelIndex = array_search(trim($catalogueNumber), $models, true);

            if ($modelIndex === false) {
                return null;
            }

            $options = [];

            for ($rowIndex = 2; $rowIndex < $rows->count(); $rowIndex++) {
                $cells = $this->rowTexts($rows->eq($rowIndex));

                if (count($cells) < 5 + count($models)) {
                    continue;
                }

                $heightIndex = count($cells) - count($models) + (int) $modelIndex;
                $size = $this->normalizeText($cells[0] ?? '');
                $shoeSize = $this->normalizeText($cells[1] ?? '');
                $footLength = $this->normalizeText($cells[2] ?? '');
                $soleLength = $this->normalizeText($cells[3] ?? '');
                $soleWidth = $this->normalizeText($cells[4] ?? '');
                $totalHeight = $this->normalizeText($cells[$heightIndex] ?? '');

                if ($size === '' || $shoeSize === '' || $totalHeight === '') {
                    continue;
                }

                $details = ['Rozmiar buta: '.$shoeSize];

                if ($footLength !== '') {
                    $details[] = 'Długość stopy: '.$footLength.' cm';
                }

                if ($soleLength !== '') {
                    $details[] = 'Długość podeszwy: '.$soleLength.' cm';
                }

                if ($soleWidth !== '') {
                    $details[] = 'Szerokość podeszwy: '.$soleWidth.' cm';
                }

                $details[] = 'Wysokość całkowita: '.$totalHeight.' cm';
                $options[] = ['label' => $size, 'value' => implode('; ', $details)];
            }

            return $options !== [] ? $options : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array{label: string, value: string}>|null */
    private function columnSizeOptions(Crawler $table): ?array
    {
        $columns = $this->sizeTableColumns($table);

        if ($columns === null) {
            return null;
        }

        try {
            $rows = $table->filter('tr');
            $options = [];

            for ($rowIndex = $columns['header_row'] + 1; $rowIndex < $rows->count(); $rowIndex++) {
                $cells = $rows->eq($rowIndex)->filter('th, td');

                if ($cells->count() <= max($columns['label_index'], $columns['value_index'])) {
                    continue;
                }

                $label = $this->normalizeText((string) $cells->eq($columns['label_index'])->text(''));
                $value = $this->normalizeText((string) $cells->eq($columns['value_index'])->text(''));

                if ($label === '' || $value === '') {
                    continue;
                }

                $labels = $this->parallelCellValues($label);
                $values = $this->parallelCellValues($value);

                if (count($labels) > 1 && count($labels) === count($values)) {
                    foreach ($labels as $index => $parallelLabel) {
                        $options[] = ['label' => $parallelLabel, 'value' => $values[$index]];
                    }

                    continue;
                }

                $options[] = ['label' => $label, 'value' => $value];
            }

            return $options !== [] ? $options : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array{label: string, value: string}>|null */
    private function directSizeRows(Crawler $table): ?array
    {
        try {
            $rows = $table->filter('tr');

            if ($rows->count() < 2) {
                return null;
            }

            $options = [];

            for ($rowIndex = 0; $rowIndex < $rows->count(); $rowIndex++) {
                $cells = $this->rowTexts($rows->eq($rowIndex));

                if (count($cells) !== 2) {
                    return null;
                }

                $label = trim($cells[0], " \t\n\r\0\x0B:");
                $value = $this->normalizeText($cells[1]);

                if (! $this->looksLikeSizeLabel($label) || $value === '') {
                    return null;
                }

                $options[] = ['label' => $label, 'value' => $value];
            }

            return $options;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array{label: string, value: string}>|null */
    private function wheelchairSizeMatrix(Crawler $table): ?array
    {
        try {
            $rows = $table->filter('tr');

            if ($rows->count() < 2) {
                return null;
            }

            $probe = mb_strtolower(implode(' ', array_merge(
                $this->rowTexts($rows->eq(0)),
                $this->rowTexts($rows->eq(1)),
            )));

            if (! str_contains($probe, 'siedzisko')) {
                return null;
            }

            $options = [];

            for ($rowIndex = 1; $rowIndex < $rows->count(); $rowIndex++) {
                $cells = $this->rowTexts($rows->eq($rowIndex));

                if (count($cells) < 2) {
                    continue;
                }

                $first = $this->normalizeText($cells[0]);
                $second = $this->normalizeText($cells[1]);

                if (preg_match('/^szer\./iu', $first) === 1 && $second !== '') {
                    $options[] = [
                        'label' => $first,
                        'value' => 'Szerokość siedziska: '.$second.' cm',
                    ];
                } elseif (preg_match('/^szerokość siedziska$/iu', $first) === 1 && $second !== '') {
                    $options[] = [
                        'label' => $second,
                        'value' => 'Szerokość siedziska: '.$second,
                    ];
                }
            }

            return $options !== [] ? $options : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function looksLikeSizeLabel(string $value): bool
    {
        return preg_match(
            '/^(?:\d+(?:[.,]\d+)?|(?:X{0,4}S|S|M|L|X{1,4}L)(?:\/(?:X{0,4}S|S|M|L|X{1,4}L))?)$/iu',
            trim($value),
        ) === 1;
    }

    /** @return array<int, string> */
    private function parallelCellValues(string $value): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $part): string => $this->normalizeText($part),
                preg_split('/\s*\/\s*/u', $value) ?: [],
            ),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /** @return array<int, string> */
    private function rowTexts(Crawler $row): array
    {
        $texts = [];
        $cells = $row->filter('th, td');

        for ($cellIndex = 0; $cellIndex < $cells->count(); $cellIndex++) {
            $texts[] = $this->normalizeText((string) $cells->eq($cellIndex)->text(''));
        }

        return $texts;
    }

    /**
     * ARmedical uses two relevant table shapes:
     * - "Rozmiar | Obwód" where the size itself is the option label;
     * - "Nr katalogowy | Rozmiar | j.m." where the catalogue number is the
     *   option label and the Rozmiar column is its value.
     *
     * DomCrawler's row text may concatenate adjacent cells (for example
     * "RozmiarObwód"), so detect headers cell-by-cell instead of relying on
     * whitespace in the combined row text.
     *
     * @return array{header_row: int, label_index: int, value_index: int}|null
     */
    private function sizeTableColumns(Crawler $table): ?array
    {
        try {
            $rows = $table->filter('tr');

            for ($rowIndex = 0; $rowIndex < min(3, $rows->count()); $rowIndex++) {
                $cells = $rows->eq($rowIndex)->filter('th, td');

                if ($cells->count() < 2) {
                    continue;
                }

                $headers = [];

                for ($cellIndex = 0; $cellIndex < $cells->count(); $cellIndex++) {
                    $headers[$cellIndex] = mb_strtolower(
                        trim($this->normalizeText((string) $cells->eq($cellIndex)->text('')), " \t\n\r\0\x0B:"),
                    );
                }

                $catalogueIndex = null;
                $sizeIndex = null;

                foreach ($headers as $cellIndex => $header) {
                    if (preg_match('/^(?:nr\.?|numer)\s+(?:kat\.?|katalogowy)$/iu', $header) === 1) {
                        $catalogueIndex = $cellIndex;
                    }

                    if (preg_match('/^(?:rozmiar|size)(?:\s|$)/iu', $header) === 1) {
                        $sizeIndex = $cellIndex;
                    }
                }

                if ($sizeIndex === null) {
                    continue;
                }

                if ($catalogueIndex !== null && $catalogueIndex !== $sizeIndex) {
                    return [
                        'header_row' => $rowIndex,
                        'label_index' => $catalogueIndex,
                        'value_index' => $sizeIndex,
                    ];
                }

                foreach ($headers as $cellIndex => $header) {
                    if ($cellIndex === $sizeIndex) {
                        continue;
                    }

                    if (in_array($header, ['j.m.', 'j.m', 'jm', 'jednostka', 'jednostka miary'], true)) {
                        continue;
                    }

                    return [
                        'header_row' => $rowIndex,
                        'label_index' => $sizeIndex,
                        'value_index' => $cellIndex,
                    ];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return array<int, array{url: string, alt: string|null, is_primary: bool}> */
    private function extractImages(Crawler $crawler, string $baseUrl): array
    {
        $images = [];

        foreach (['main img', 'article img', '.entry-content img', 'img'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $baseUrl): void {
                    $raw = (string) ($node->attr('data-src') ?: $node->attr('src'));
                    $url = $this->normalizeAssetUrl($raw, $baseUrl);

                    if ($url === null || ! preg_match('/\.(?:jpe?g|png|webp)(?:$|\?)/i', $url)) {
                        return;
                    }

                    if ($this->isWordPressThumbnail($url)) {
                        return;
                    }

                    if ($this->isDecorativeImage($url, (string) $node->attr('class'), (string) $node->attr('alt'))) {
                        return;
                    }

                    $images[$url] = [
                        'url' => $url,
                        'alt' => ($alt = $this->normalizeText((string) $node->attr('alt'))) !== '' ? $alt : null,
                        'is_primary' => false,
                    ];
                });
            } catch (Throwable) {
                continue;
            }

            if ($images !== []) {
                break;
            }
        }

        $images = array_values($images);

        if (isset($images[0])) {
            $images[0]['is_primary'] = true;
        }

        return $images;
    }

    /** @return array<int, array{url: string, label: string, type: string}> */
    private function extractDocuments(Crawler $crawler, string $baseUrl): array
    {
        $documents = [];

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$documents, $baseUrl): void {
                $href = html_entity_decode((string) $node->attr('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = $this->normalizeText((string) $node->text(''));
                $url = $this->normalizeAssetUrl($href, $baseUrl);

                if ($url === null) {
                    return;
                }

                if ($this->isSiteWideDocument($url, $label)) {
                    return;
                }

                $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
                $isDocument = preg_match('/\.(?:pdf|docx?|xlsx?|odt)$/i', $path) === 1;
                $isUpload = str_contains($path, '/wp-content/uploads/');
                $looksLikeDocument = preg_match('/instruk|dokument|deklarac|certyf|rejestr|karta/u', mb_strtolower($label)) === 1;

                if (! $isDocument && ! ($looksLikeDocument && $isUpload)) {
                    return;
                }

                $documents[$url] = [
                    'url' => $url,
                    'label' => $label !== '' ? $label : basename($path),
                    'type' => $this->documentType($label, $path),
                ];
            });
        } catch (Throwable) {
            return [];
        }

        return array_values($documents);
    }

    private function documentType(string $label, string $path): string
    {
        $value = mb_strtolower($label.' '.$path);

        return match (true) {
            str_contains($value, 'instruk') => 'manual',
            str_contains($value, 'deklarac') => 'declaration',
            str_contains($value, 'certyf') => 'certificate',
            str_contains($value, 'rejestr') => 'registration',
            default => 'document',
        };
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://armedical.pl'.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            $base = rtrim(dirname((string) parse_url($baseUrl, PHP_URL_PATH)), '/');
            $url = 'https://armedical.pl'.$base.'/'.$url;
        }

        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['armedical.pl', 'www.armedical.pl'], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://armedical.pl'.$path.$query;
    }

    private function isWordPressThumbnail(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('/-\d{2,4}x\d{2,4}\.(?:jpe?g|png|webp)$/i', $path) === 1;
    }

    private function isSiteWideDocument(string $url, string $label): bool
    {
        $value = mb_strtolower($url.' '.$label);

        return str_contains($value, 'dsa-regulamin-social-media')
            || str_contains($value, 'regulamin social media')
            || str_contains($value, 'polityka-prywatnosci')
            || str_contains($value, 'polityka prywatności');
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $sizeOptions
     * @return array<int, string>
     */
    private function catalogueConsistencyWarnings(?string $catalogueNumber, array $sizeOptions): array
    {
        if ($sizeOptions === []) {
            return [];
        }

        $warnings = [];
        $valuesByLabel = [];

        foreach ($sizeOptions as $option) {
            $label = trim($option['label']);
            $value = trim($option['value']);
            $valuesByLabel[$label][$value] = true;
        }

        foreach ($valuesByLabel as $label => $values) {
            if (count($values) > 1) {
                $warnings[] = sprintf(
                    'Source size table reuses catalogue/size label %s for multiple values; review source data before import.',
                    $label,
                );
            }
        }

        if (
            $catalogueNumber !== null
            && preg_match('/^(\d{4,5})\s+do\s+(\d{4,5})$/u', trim($catalogueNumber), $matches) === 1
            && $matches[1] === $matches[2]
            && count($sizeOptions) > 1
        ) {
            $warnings[] = sprintf(
                'Source catalogue range %s is inconsistent with %d size-table rows; review source data before import.',
                $catalogueNumber,
                count($sizeOptions),
            );
        }

        return $warnings;
    }

    private function isDecorativeImage(string $url, string $class, string $alt): bool
    {
        $value = mb_strtolower($url.' '.$class.' '.$alt);

        foreach (['logo', 'favicon', 'icon', 'loader', 'sprite', 'avatar', 'facebook', 'instagram', 'youtube'] as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isMedicalDevice(string $html): bool
    {
        $plain = mb_strtolower($this->normalizeText(strip_tags($html)));

        return str_contains($plain, 'to jest wyrób medyczny')
            || str_contains($plain, 'jest wyrobem medycznym')
            || str_contains($plain, 'wyrób medyczny');
    }

    private function firstParagraph(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        try {
            $crawler = new Crawler($html);
            $paragraph = $this->normalizeText((string) $crawler->filter('p')->first()->text(''));
        } catch (Throwable) {
            return null;
        }

        return $paragraph !== '' ? $paragraph : null;
    }

    private function firstText(Crawler $crawler, array $selectors): string
    {
        foreach ($selectors as $selector) {
            try {
                $value = $this->normalizeText((string) $crawler->filter($selector)->first()->text(''));
            } catch (Throwable) {
                continue;
            }

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function firstMetaContent(Crawler $crawler, string $attribute, string $value): ?string
    {
        try {
            $content = $this->normalizeText((string) $crawler->filter(sprintf('meta[%s="%s"]', $attribute, $value))->first()->attr('content'));
        } catch (Throwable) {
            return null;
        }

        return $content !== '' ? $content : null;
    }

    /** @return array{0: string|null, 1: string} */
    private function splitCatalogueNumberAndName(string $text): array
    {
        if (preg_match('/^((?:\d{4,5}\s+do\s+\d{4,5})|(?:[A-Z]{1,5}-?[A-Z0-9]{1,10}(?:-[A-Z0-9]{1,10})*(?:\s*\/\s*[A-Z]{1,5}-?[A-Z0-9]{1,10}(?:-[A-Z0-9]{1,10})*)*))\s+(.+)$/iu', $text, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [null, $this->normalizeText($text)];
    }

    private function singleCatalogueNumber(?string $catalogueNumber): ?string
    {
        if ($catalogueNumber === null || preg_match('/^(?:[A-Z]{1,5}-?[A-Z0-9]{1,10}(?:-[A-Z0-9]{1,10})*|\d{4,5})$/i', trim($catalogueNumber)) !== 1) {
            return null;
        }

        return trim($catalogueNumber);
    }

    private function isNonCategoryLabel(string $label, string $name): bool
    {
        $normalized = mb_strtolower($label);

        return $normalized === mb_strtolower($name)
            || in_array($normalized, ['strona główna', 'home', 'oferta', 'katalog', 'katalog produktów'], true);
    }

    private function contextString(?array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
