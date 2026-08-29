<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class NeoxmedProductPageScraper
{
    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 750;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    public function __construct(
        private readonly NeoxmedCategoryUrlScraper $categoryUrlScraper,
    ) {}

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
     * @param  array<string, mixed>|null  $categoryContext
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $categoryContext = null): array
    {
        $normalizedUrl = $this->normalizeCategoryUrl($url);

        if ($normalizedUrl === null) {
            return $this->failureResult($url, 'Unsupported NeoxMed category URL.');
        }

        $this->emit('Fetching NeoxMed product page: '.$normalizedUrl);
        $failed = [];
        $html = $this->fetchBody($normalizedUrl, $failed);

        if ($html === null) {
            return [
                'source' => 'neoxmed',
                'source_url' => $normalizedUrl,
                'category' => $categoryContext,
                'products' => [],
                'product_count' => 0,
                'warnings' => [],
                'failed_urls' => $failed,
            ];
        }

        $result = $this->extract($html, $normalizedUrl, $categoryContext);
        $result['failed_urls'] = $failed;

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $categoryContext
     * @return array<string, mixed>
     */
    public function extract(string $html, string $url, ?array $categoryContext = null): array
    {
        $normalizedUrl = $this->normalizeCategoryUrl($url) ?? $url;
        $categoryName = $this->categoryName($html, $categoryContext, $normalizedUrl);
        $categorySlug = $this->categorySlug($categoryContext, $normalizedUrl);
        $headings = $this->productHeadings($html);
        $productsByCode = [];
        $warnings = [];

        foreach ($headings as $index => $heading) {
            $sectionEnd = $headings[$index + 1]['start'] ?? strlen($html);
            $sectionHtml = substr($html, (int) $heading['end'], max(0, $sectionEnd - (int) $heading['end']));
            $sectionHtml = $this->trimSectionAtFooter($sectionHtml);
            $sourceCode = (string) $heading['code'];
            $name = (string) $heading['name'];
            $identity = $this->productIdentity($sourceCode, $name);
            $externalProductId = $identity['external_product_id'];
            $sectionData = $this->sectionData(
                $sectionHtml,
                $html,
                $sourceCode,
                $externalProductId,
                $identity['qualifier'],
                $normalizedUrl,
            );

            if (isset($productsByCode[$externalProductId])) {
                $productsByCode[$externalProductId] = $this->mergeDuplicateProduct(
                    $productsByCode[$externalProductId],
                    $sectionData,
                    $categoryName,
                    $normalizedUrl,
                );

                continue;
            }

            $descriptionLines = $sectionData['description_lines'];
            $productWarnings = [];

            if ($sectionData['size_note'] !== null || $sectionData['size_chart_images'] !== []) {
                $productWarnings[] = 'NeoxMed publishes size information visually; variant sizes require review before import mapping.';
            }

            if ($sectionData['images'] === []) {
                $productWarnings[] = 'No product image matching the NeoxMed product code was found.';
            }

            $identityWarnings = [];

            if ($externalProductId !== $sourceCode) {
                $identityWarnings[] = sprintf(
                    'NeoxMed reuses source code %s for multiple catalogue products; derived SKU %s preserves the distinct source heading.',
                    $sourceCode,
                    $externalProductId,
                );
            }

            $productsByCode[$externalProductId] = [
                'source' => 'neoxmed',
                'source_url' => $normalizedUrl,
                'source_locator' => $normalizedUrl.'#'.strtolower($externalProductId),
                'canonical_url' => null,
                'source_code' => $sourceCode,
                'source_qualifier' => $identity['qualifier'],
                'external_product_id' => $externalProductId,
                'sku' => $externalProductId,
                'slug' => Str::slug($externalProductId.' '.$name),
                'name' => $name,
                'brand' => [
                    'name' => 'Neox',
                    'slug' => 'neox',
                ],
                'category' => $categoryName,
                'categories' => [$categoryName],
                'source_category_name' => $categoryName,
                'source_category_url' => $normalizedUrl,
                'source_category_path' => [$categoryName],
                'source_category_paths' => [[$categoryName]],
                'source_category_slug' => $categorySlug,
                'description_text' => implode("\n", $descriptionLines),
                'description_lines' => $descriptionLines,
                'description_html' => $this->descriptionHtml($descriptionLines),
                'nfz_codes' => $sectionData['nfz_codes'],
                'size_note' => $sectionData['size_note'],
                'images' => $sectionData['images'],
                'size_chart_images' => $sectionData['size_chart_images'],
                'variant_candidates' => [],
                'price_gross_amount' => null,
                'currency' => null,
                'availability' => null,
                'is_medical_device' => true,
                'warnings' => array_values(array_unique(array_merge($productWarnings, $identityWarnings))),
            ];
        }

        $productsByCode = $this->propagateSharedSourceCodeMetadata($productsByCode);

        if ($productsByCode === []) {
            $warnings[] = 'No NeoxMed product headings were found on the category page.';
        }

        return [
            'source' => 'neoxmed',
            'source_url' => $normalizedUrl,
            'category' => [
                'name' => $categoryName,
                'slug' => $categorySlug,
                'url' => $normalizedUrl,
            ],
            'products' => array_values($productsByCode),
            'product_count' => count($productsByCode),
            'warnings' => $warnings,
            'failed_urls' => [],
        ];
    }

    public function normalizeCategoryUrl(string $url): ?string
    {
        $normalized = $this->categoryUrlScraper->normalizeUrl($url);

        if ($normalized === null) {
            return null;
        }

        $path = parse_url($normalized, PHP_URL_PATH);

        if (! is_string($path) || $path === '/') {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<int, array{start:int,end:int,code:string,name:string}>
     */
    private function productHeadings(string $html): array
    {
        $matchCount = preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/isu', $html, $matches, PREG_OFFSET_CAPTURE);

        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        $headings = [];

        foreach ($matches[0] as $index => $fullMatch) {
            $headingText = $this->normalizeText((string) ($matches[1][$index][0] ?? ''));
            $parsed = $this->parseProductHeading($headingText);

            if ($parsed === null) {
                continue;
            }

            $rawHeading = (string) $fullMatch[0];
            $start = (int) $fullMatch[1];

            $headings[] = [
                'start' => $start,
                'end' => $start + strlen($rawHeading),
                'code' => $parsed['code'],
                'name' => $parsed['name'],
            ];
        }

        return $headings;
    }

    /**
     * @return array{code:string,name:string}|null
     */
    private function parseProductHeading(string $heading): ?array
    {
        if (preg_match('/^([A-ZĄĆĘŁŃÓŚŹŻ]{1,5}\s*[-–]\s*\d{1,3}[A-Z]?)\s+(.+)$/u', $heading, $matches) !== 1) {
            return null;
        }

        $code = strtoupper((string) preg_replace('/\s+/u', '', str_replace('–', '-', $matches[1])));
        $name = trim((string) preg_replace('/\s+/u', ' ', $matches[2]));

        if ($name === '') {
            return null;
        }

        return ['code' => $code, 'name' => $name];
    }

    /**
     * @return array{
     *     description_lines: array<int,string>,
     *     nfz_codes: array<int,string>,
     *     size_note: ?string,
     *     images: array<int,array{url:string,alt:?string}>,
     *     size_chart_images: array<int,array{url:string,alt:?string}>
     * }
     */
    private function sectionData(
        string $sectionHtml,
        string $fullHtml,
        string $sourceCode,
        string $externalProductId,
        ?string $qualifier,
        string $baseUrl,
    ): array {
        $lines = $this->sectionLines($sectionHtml);
        $nfzCodes = $this->nfzCodes($sectionHtml);
        $sizeNote = null;
        $descriptionLines = [];

        foreach ($lines as $line) {
            if ($this->isFooterLine($line)) {
                break;
            }

            if (preg_match('/^(?:Dostępne\s+rozmiary|Rozmiar\s+uniwersalny)/iu', $line) === 1) {
                $sizeNote ??= $line;

                continue;
            }

            if (str_starts_with(mb_strtoupper($line), 'NFZ:')) {
                continue;
            }

            $descriptionLines[] = $line;
        }

        [$images, $sizeChartImages] = $this->imagesForProduct(
            $fullHtml,
            $sectionHtml,
            $sourceCode,
            $externalProductId,
            $qualifier,
            $baseUrl,
        );

        return [
            'description_lines' => array_values(array_unique($descriptionLines)),
            'nfz_codes' => $nfzCodes,
            'size_note' => $sizeNote,
            'images' => $images,
            'size_chart_images' => $sizeChartImages,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sectionLines(string $html): array
    {
        $html = preg_replace('/<(?:br|\/p|\/li|\/div|\/section|\/h[1-6])\b[^>]*>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R+/u', $text) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $line = $this->normalizeText($line);

            if ($line === '') {
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function nfzCodes(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/\b[A-Z]\.[0-9]{2}\.[0-9]{2}\.[0-9]{2}\b/u', $text, $matches);

        return array_values(array_unique(array_map('strval', $matches[0] ?? [])));
    }

    /**
     * @return array{0:array<int,array{url:string,alt:?string}>,1:array<int,array{url:string,alt:?string}>}
     */
    private function imagesForProduct(
        string $fullHtml,
        string $sectionHtml,
        string $sourceCode,
        string $externalProductId,
        ?string $qualifier,
        string $baseUrl,
    ): array {
        $images = [];
        $sizeCharts = [];
        $codeNeedle = $this->alphaNumericKey(
            $qualifier !== null && ctype_digit($qualifier) ? $externalProductId : $sourceCode,
        );

        foreach ($this->imageTags($fullHtml, $baseUrl) as $image) {
            $identity = $this->alphaNumericKey(($image['alt'] ?? '').' '.basename((string) parse_url($image['url'], PHP_URL_PATH)));

            if ($identity === '' || ! str_contains($identity, $codeNeedle)) {
                continue;
            }

            if ($this->looksLikeSizeChart($image)) {
                $sizeCharts[$image['url']] = $image;
            } else {
                $images[$image['url']] = $image;
            }
        }

        foreach ($this->imageTags($sectionHtml, $baseUrl) as $image) {
            if (! $this->looksLikeSizeChart($image)) {
                continue;
            }

            $sizeCharts[$image['url']] = $image;
        }

        return [array_values($images), array_values($sizeCharts)];
    }

    /**
     * @return array<int,array{url:string,alt:?string}>
     */
    private function imageTags(string $html, string $baseUrl): array
    {
        preg_match_all('/<img\b[^>]*>/isu', $html, $matches);
        $images = [];

        foreach ($matches[0] ?? [] as $tag) {
            $url = null;

            foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attribute) {
                $candidate = $this->htmlAttribute($tag, $attribute);

                if ($candidate !== null && $candidate !== '' && ! str_starts_with($candidate, 'data:')) {
                    $url = $this->normalizeImageUrl($candidate, $baseUrl);

                    if ($url !== null) {
                        break;
                    }
                }
            }

            if ($url === null) {
                continue;
            }

            $alt = $this->normalizeText($this->htmlAttribute($tag, 'alt') ?? '');
            $images[$url] = [
                'url' => $url,
                'alt' => $alt !== '' ? $alt : null,
            ];
        }

        return array_values($images);
    }

    private function htmlAttribute(string $tag, string $attribute): ?string
    {
        if (preg_match('/\b'.preg_quote($attribute, '/').'\s*=\s*(["\'])(.*?)\1/isu', $tag, $matches) !== 1) {
            return null;
        }

        return html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function normalizeImageUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (str_starts_with($url, '/')) {
            return 'https://neoxmed.com'.$url;
        }

        if (parse_url($url, PHP_URL_SCHEME) === null) {
            $path = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');

            return 'https://neoxmed.com'.$path.'/'.$url;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /**
     * @param  array{url:string,alt:?string}  $image
     */
    private function looksLikeSizeChart(array $image): bool
    {
        $path = strtolower((string) parse_url($image['url'], PHP_URL_PATH));
        $base = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $alt = mb_strtolower((string) ($image['alt'] ?? ''));

        if (
            preg_match('/_resize(?:-\d+x\d+)?$/', $base) === 1
            || preg_match('/_resize(?:-\d+x\d+)?$/', $alt) === 1
        ) {
            return false;
        }

        return str_contains($base, 'size')
            || $base === 'uni'
            || str_contains($base, '_')
            || str_contains($alt, 'rozmiar')
            || str_contains($alt, 'size');
    }

    private function alphaNumericKey(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
    }

    /**
     * @return array{external_product_id:string,qualifier:?string}
     */
    private function productIdentity(string $sourceCode, string $name): array
    {
        $qualifier = null;

        if (preg_match('/^\((\d{1,3})\)\s+/u', $name, $matches) === 1) {
            $qualifier = (string) $matches[1];
        } elseif (preg_match('/^Short\b/iu', $name) === 1) {
            $qualifier = 'SHORT';
        }

        return [
            'external_product_id' => $qualifier === null ? $sourceCode : $sourceCode.'-'.$qualifier,
            'qualifier' => $qualifier,
        ];
    }

    /**
     * NeoxMed occasionally publishes NFZ metadata immediately before/after a
     * sibling heading that shares the same source code. Propagate only NFZ
     * codes across those siblings; product images/descriptions stay isolated.
     *
     * @param  array<string,array<string,mixed>>  $products
     * @return array<string,array<string,mixed>>
     */
    private function propagateSharedSourceCodeMetadata(array $products): array
    {
        $nfzBySourceCode = [];

        foreach ($products as $product) {
            $sourceCode = is_string($product['source_code'] ?? null) ? $product['source_code'] : null;

            if ($sourceCode === null) {
                continue;
            }

            $nfzBySourceCode[$sourceCode] = array_values(array_unique(array_merge(
                $nfzBySourceCode[$sourceCode] ?? [],
                is_array($product['nfz_codes'] ?? null) ? $product['nfz_codes'] : [],
            )));
        }

        foreach ($products as $key => $product) {
            $sourceCode = is_string($product['source_code'] ?? null) ? $product['source_code'] : null;

            if ($sourceCode === null || ($nfzBySourceCode[$sourceCode] ?? []) === []) {
                continue;
            }

            $products[$key]['nfz_codes'] = $nfzBySourceCode[$sourceCode];
        }

        return $products;
    }

    /**
     * @param  array<int,string>  $lines
     */
    private function descriptionHtml(array $lines): ?string
    {
        if ($lines === []) {
            return null;
        }

        $items = array_map(
            static fn (string $line): string => '<li>'.htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>',
            $lines,
        );

        return '<ul>'.implode('', $items).'</ul>';
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $duplicateSection
     * @return array<string,mixed>
     */
    private function mergeDuplicateProduct(array $existing, array $duplicateSection, string $categoryName, string $categoryUrl): array
    {
        $existing['categories'] = array_values(array_unique(array_merge(
            is_array($existing['categories'] ?? null) ? $existing['categories'] : [],
            [$categoryName],
        )));

        $paths = is_array($existing['source_category_paths'] ?? null) ? $existing['source_category_paths'] : [];
        $path = [$categoryName];

        if (! in_array($path, $paths, true)) {
            $paths[] = $path;
        }

        $existing['source_category_paths'] = $paths;
        $existing['images'] = $this->mergeImages($existing['images'] ?? [], $duplicateSection['images'] ?? []);
        $existing['size_chart_images'] = $this->mergeImages($existing['size_chart_images'] ?? [], $duplicateSection['size_chart_images'] ?? []);
        $existing['nfz_codes'] = array_values(array_unique(array_merge(
            is_array($existing['nfz_codes'] ?? null) ? $existing['nfz_codes'] : [],
            is_array($duplicateSection['nfz_codes'] ?? null) ? $duplicateSection['nfz_codes'] : [],
        )));

        if (($existing['source_category_url'] ?? null) === null) {
            $existing['source_category_url'] = $categoryUrl;
        }

        return $existing;
    }

    /**
     * @param  array<int,array{url:string,alt:?string}>  $left
     * @param  array<int,array{url:string,alt:?string}>  $right
     * @return array<int,array{url:string,alt:?string}>
     */
    private function mergeImages(array $left, array $right): array
    {
        $images = [];

        foreach (array_merge($left, $right) as $image) {
            if (! is_array($image) || ! is_string($image['url'] ?? null)) {
                continue;
            }

            $images[$image['url']] = $image;
        }

        return array_values($images);
    }

    /**
     * @param  array<string,mixed>|null  $categoryContext
     */
    private function categoryName(string $html, ?array $categoryContext, string $url): string
    {
        if (is_string($categoryContext['name'] ?? null) && trim((string) $categoryContext['name']) !== '') {
            return trim((string) $categoryContext['name']);
        }

        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/isu', $html, $matches) === 1) {
            $name = $this->normalizeText((string) $matches[1]);

            if ($name !== '') {
                return $name;
            }
        }

        return Str::headline($this->categorySlug($categoryContext, $url));
    }

    /**
     * @param  array<string,mixed>|null  $categoryContext
     */
    private function categorySlug(?array $categoryContext, string $url): string
    {
        if (is_string($categoryContext['slug'] ?? null) && trim((string) $categoryContext['slug']) !== '') {
            return trim((string) $categoryContext['slug']);
        }

        return trim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    private function trimSectionAtFooter(string $html): string
    {
        $markers = ['<footer', '>Kontakt<', 'Zagórze 239', 'Privacy Overview', 'Copyright:'];
        $positions = [];

        foreach ($markers as $marker) {
            $position = mb_stripos($html, $marker);

            if ($position !== false) {
                $positions[] = $position;
            }
        }

        if ($positions === []) {
            return $html;
        }

        return mb_substr($html, 0, min($positions));
    }

    private function isFooterLine(string $line): bool
    {
        $normalized = mb_strtolower($line);

        return $normalized === 'kontakt'
            || str_starts_with($normalized, 'tel.:')
            || str_starts_with($normalized, 'zagórze 239')
            || str_starts_with($normalized, 'copyright:')
            || str_contains($normalized, 'privacy overview');
    }

    /**
     * @param  array<string,string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();

                    continue;
                }

                $failed[$url] = $exception->getMessage();

                return null;
            }

            if ($response->successful()) {
                return $response->body();
            }

            if ($attempt < $this->maxAttempts && $this->isRetryableResponse($response)) {
                $this->pauseBeforeRetry();

                continue;
            }

            $failed[$url] = 'HTTP '.$response->status();

            return null;
        }

        return null;
    }

    private function isRetryableResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopCatalogBot/1.0; +https://konji.pl)',
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

    private function normalizeText(string $value): string
    {
        $value = preg_replace('/<[^>]+>/u', ' ', $value) ?? $value;
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array<string,mixed>
     */
    private function failureResult(string $url, string $reason): array
    {
        return [
            'source' => 'neoxmed',
            'source_url' => $url,
            'category' => null,
            'products' => [],
            'product_count' => 0,
            'warnings' => [],
            'failed_urls' => [$url => $reason],
        ];
    }
}
