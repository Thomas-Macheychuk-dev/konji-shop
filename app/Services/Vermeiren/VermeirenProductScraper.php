<?php

declare(strict_types=1);

namespace App\Services\Vermeiren;

use Closure;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class VermeirenProductScraper
{
    private const VERMEIREN_HOST = 'www.vermeiren.pl';

    private const BASE_URL = 'https://www.vermeiren.pl/web/web.nsf/';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 500;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

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

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
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

        $this->emit('Fetching Vermeiren product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch Vermeiren product page.';

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
        try {
            $crawler = new Crawler($html, $url);
        } catch (Throwable $exception) {
            $warnings[] = 'Unable to parse Vermeiren product page: '.$exception->getMessage();

            return $this->emptyResult($url, $productLinkContext, $failed, $warnings);
        }

        $sourceUrl = $this->normalizeProductUrl($url) ?? $url;
        $reference = $this->productReference($sourceUrl);
        $pageName = $this->extractPageName($crawler);
        $contextName = $this->normalizeText((string) ($productLinkContext['name'] ?? ''));
        $name = $contextName !== '' ? $contextName : $pageName;
        $selectedName = $this->normalizeText((string) (
            $productLinkContext['selected_name']
            ?? $reference['selected_name']
            ?? $name
        ));
        $descriptionHtml = $this->extractDescriptionHtml($crawler, $sourceUrl);
        $description = $this->plainTextFromHtml($descriptionHtml) ?? '';
        $shortDescription = $this->firstTextByIdSuffix($crawler, ':label1');

        if ($description === '' && $shortDescription !== '') {
            $description = $shortDescription;
            $descriptionHtml = '<p>'.htmlspecialchars(
                $shortDescription,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            ).'</p>';
        }
        $images = $this->extractImages($crawler, $sourceUrl, $name);
        $technicalSpecifications = $this->extractTechnicalSpecifications($crawler, $sourceUrl);
        $colors = $this->extractColors($crawler, $sourceUrl);
        $options = $this->extractOptions($crawler, $sourceUrl);
        $documents = $this->extractDocuments($crawler, $sourceUrl);
        $categoryPaths = $this->categoryPaths($productLinkContext, $reference);
        $categoryUrls = $this->stringList($productLinkContext['category_urls'] ?? []);
        $category = $this->leafCategory($categoryPaths);
        $externalProductId = $this->normalizeText((string) (
            $productLinkContext['external_id']
            ?? $reference['external_id']
            ?? hash('sha256', rawurldecode((string) parse_url($sourceUrl, PHP_URL_QUERY)))
        ));
        $brand = $this->extractBrand($crawler) ?? 'Vermeiren';
        $seoTitle = $this->normalizeText($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->metaContent($crawler, 'description');

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($description === '') {
            $warnings[] = 'Product description was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        return [
            'source' => 'vermeiren',
            'source_url' => $sourceUrl,
            'canonical_url' => $sourceUrl,
            'external_product_id' => $externalProductId !== '' ? $externalProductId : null,
            'source_key' => $reference['source_key'] ?? null,
            'name' => $name,
            'selected_name' => $selectedName !== '' ? $selectedName : null,
            'brand' => $brand,
            'product_group' => $this->contextString($productLinkContext, 'product_group')
                ?? ($reference['product_group'] ?? null),
            'sub_group' => $this->contextString($productLinkContext, 'sub_group')
                ?? ($reference['sub_group'] ?? null),
            'sub_sub_group' => $this->contextString($productLinkContext, 'sub_sub_group')
                ?? ($reference['sub_sub_group'] ?? null),
            'category' => $category,
            'category_urls' => $categoryUrls,
            'category_paths' => $categoryPaths,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'short_description' => $shortDescription !== '' ? $shortDescription : null,
            'description' => $description,
            'description_html' => $descriptionHtml,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => $selectedName !== '' ? $selectedName : ($name !== '' ? $name : null),
            'ean' => null,
            'technical_specifications' => $technicalSpecifications,
            'attributes' => $this->attributesFromSpecifications($technicalSpecifications),
            'colors' => $colors,
            'options' => $options,
            'documents' => $documents,
            'images' => $images,
            'variant_candidates' => [],
            'is_medical_device' => $this->isMedicalDevice($html, $documents),
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ];
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeVermeirenUrl($url, $baseUrl ?? self::BASE_URL);

        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        if (strtolower(pathinfo($path, PATHINFO_FILENAME)) !== 'detailproduct') {
            return null;
        }

        return $normalized;
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
                $request = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers());

                if (! $this->verifyTls) {
                    $request = $request->withoutVerifying();
                }

                $response = $request->get($url);
            } catch (Throwable $exception) {
                $lastReason = $exception->getMessage();

                if ($attempt < $this->maxAttempts) {
                    $this->emitRetry($url, $attempt, $lastReason);
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
                $this->emitRetry($url, $attempt, $lastReason);
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
        $reference = $this->productReference($sourceUrl);
        $categoryPaths = $this->categoryPaths($productLinkContext, $reference);
        $selectedName = $this->normalizeText((string) (
            $productLinkContext['selected_name']
            ?? $reference['selected_name']
            ?? ''
        ));

        return [
            'source' => 'vermeiren',
            'source_url' => $sourceUrl,
            'canonical_url' => $sourceUrl,
            'external_product_id' => $productLinkContext['external_id'] ?? $reference['external_id'] ?? null,
            'source_key' => $reference['source_key'] ?? null,
            'name' => $this->normalizeText((string) ($productLinkContext['name'] ?? $selectedName)),
            'selected_name' => $selectedName !== '' ? $selectedName : null,
            'brand' => 'Vermeiren',
            'product_group' => $this->contextString($productLinkContext, 'product_group')
                ?? ($reference['product_group'] ?? null),
            'sub_group' => $this->contextString($productLinkContext, 'sub_group')
                ?? ($reference['sub_group'] ?? null),
            'sub_sub_group' => $this->contextString($productLinkContext, 'sub_sub_group')
                ?? ($reference['sub_sub_group'] ?? null),
            'category' => $this->leafCategory($categoryPaths),
            'category_urls' => $this->stringList($productLinkContext['category_urls'] ?? []),
            'category_paths' => $categoryPaths,
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
            'sku' => $selectedName !== '' ? $selectedName : null,
            'ean' => null,
            'technical_specifications' => [],
            'attributes' => [],
            'colors' => [],
            'options' => [],
            'documents' => [],
            'images' => [],
            'variant_candidates' => [],
            'is_medical_device' => false,
            'warnings' => array_values(array_unique($warnings)),
            'failed_urls' => $failed,
        ];
    }

    private function extractPageName(Crawler $crawler): string
    {
        $name = $this->firstTextByIdSuffix($crawler, ':prodNaam');
        $title = $this->normalizeText($crawler->filter('title')->first()->text(''));
        $title = preg_replace('/\s*\|\s*Vermeiren(?:\s+Polska)?\s*$/iu', '', $title) ?? $title;

        if ($name === '') {
            return $title;
        }

        if ($title !== '' && mb_strlen($title) > mb_strlen($name)) {
            return $title;
        }

        return $name;
    }

    private function extractDescriptionHtml(Crawler $crawler, string $baseUrl): ?string
    {
        $container = $crawler->filterXPath('//*[@id="view:_id1:repeat5"]')->first();

        if ($container->count() === 0) {
            return null;
        }

        $html = '';

        $container->filter('.xspInputFieldRichText')->each(function (Crawler $node) use (&$html): void {
            $html .= $this->innerHtml($node);
        });

        if (trim(strip_tags($html)) === '') {
            return null;
        }

        return $this->normalizeContentHtml($html, $baseUrl);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractImages(Crawler $crawler, string $baseUrl, string $name): array
    {
        $images = [];
        $seen = [];
        $position = 0;
        $main = $crawler->filterXPath('//*[substring(@id, string-length(@id) - string-length(":picture") + 1) = ":picture"]//img[@src]')->first();

        if ($main->count() > 0) {
            $url = $this->normalizeAssetUrl((string) $main->attr('src'), $baseUrl);

            if ($url !== null) {
                $key = $this->imageFileKey($url);
                $seen[$key] = true;
                $images[] = [
                    'url' => $url,
                    'thumbnail_url' => null,
                    'alt' => $this->normalizeText((string) ($main->attr('alt') ?: $name)),
                    'position' => $position++,
                    'is_primary' => true,
                ];
            }
        }

        $crawler->filter('#gallery img[data-image]')->each(function (Crawler $image) use (
            &$images,
            &$seen,
            &$position,
            $baseUrl,
            $name,
        ): void {
            $url = $this->normalizeAssetUrl((string) $image->attr('data-image'), $baseUrl);

            if ($url === null) {
                return;
            }

            $key = $this->imageFileKey($url);

            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $images[] = [
                'url' => $url,
                'thumbnail_url' => $this->normalizeAssetUrl((string) $image->attr('src'), $baseUrl),
                'alt' => $this->normalizeText((string) ($image->attr('alt') ?: $name)),
                'position' => $position++,
                'is_primary' => $images === [],
            ];
        });

        return $images;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractTechnicalSpecifications(Crawler $crawler, string $baseUrl): array
    {
        $icons = [];
        $values = [];

        $crawler->filter('#techn img[src*="/technicaldetails.nsf/"]')->each(function (Crawler $image) use (&$icons, $baseUrl): void {
            $url = $this->normalizeAssetUrl((string) $image->attr('src'), $baseUrl);

            if ($url !== null) {
                $icons[] = $url;
            }
        });

        $crawler->filter('#techn [id*=":repeat3:"][id$=":inputRichText1"]')->each(function (Crawler $value) use (&$values): void {
            $values[] = $this->plainTextFromHtml($this->innerHtml($value)) ?? '';
        });

        $specifications = [];
        $count = min(count($icons), count($values));

        for ($index = 0; $index < $count; $index++) {
            $value = $values[$index];

            if ($value === '') {
                continue;
            }

            $sourceLabel = $this->technicalSourceLabel($icons[$index]);
            $specifications[] = [
                'key' => $this->technicalKey($sourceLabel),
                'label' => $this->technicalLabel($sourceLabel),
                'source_label' => $sourceLabel,
                'value' => $value,
                'icon_url' => $icons[$index],
                'position' => $index,
            ];
        }

        return $specifications;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractColors(Crawler $crawler, string $baseUrl): array
    {
        $colors = [];
        $seen = [];

        $crawler->filter('h5')->each(function (Crawler $heading) use (&$colors, &$seen, $baseUrl): void {
            $label = $this->normalizeText($heading->text(''));
            $type = match (mb_strtolower($label)) {
                'kolory tapicerki' => 'upholstery',
                'kolory ramy' => 'frame',
                default => null,
            };

            if ($type === null) {
                return;
            }

            $parent = $heading->getNode(0)?->parentNode;

            if (! $parent instanceof DOMElement) {
                return;
            }

            (new Crawler($parent, $baseUrl))->filter('img[src*="/product/colors.nsf/"]')->each(
                function (Crawler $image) use (&$colors, &$seen, $baseUrl, $type): void {
                    $url = $this->normalizeAssetUrl((string) $image->attr('src'), $baseUrl);

                    if ($url === null) {
                        return;
                    }

                    $name = $this->assetName($url);
                    $key = $type.'|'.mb_strtolower($name);

                    if ($name === '' || isset($seen[$key])) {
                        return;
                    }

                    $seen[$key] = true;
                    $colors[] = [
                        'type' => $type,
                        'name' => $name,
                        'image_url' => $url,
                    ];
                }
            );
        });

        return $colors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractOptions(Crawler $crawler, string $baseUrl): array
    {
        $options = [];
        $seen = [];

        $crawler->filter('#opt .offer')->each(function (Crawler $offer) use (&$options, &$seen, $baseUrl): void {
            $name = $this->normalizeText($offer->text(''));
            $fullImage = $offer->filter('[data-original]')->first();
            $thumbnail = $offer->filter('img[src]')->first();
            $imageUrl = $fullImage->count() > 0
                ? $this->normalizeAssetUrl((string) $fullImage->attr('data-original'), $baseUrl)
                : null;
            $thumbnailUrl = $thumbnail->count() > 0
                ? $this->normalizeAssetUrl((string) $thumbnail->attr('src'), $baseUrl)
                : null;
            $key = mb_strtolower($name).'|'.($imageUrl ?? $thumbnailUrl ?? '');

            if ($name === '' || isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $options[] = [
                'name' => $name,
                'image_url' => $imageUrl,
                'thumbnail_url' => $thumbnailUrl,
            ];
        });

        return $options;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractDocuments(Crawler $crawler, string $baseUrl): array
    {
        $paneTypes = [
            'doc_brochures' => 'brochure',
            'doc_bestelbon' => 'order_form',
            'doc_gebr' => 'manual',
            'doc_cert' => 'certificate',
            'doc_ond' => 'spare_part',
        ];
        $documents = [];
        $seen = [];

        foreach ($paneTypes as $paneId => $type) {
            $crawler->filter('#'.$paneId.' a[href]')->each(function (Crawler $anchor) use (
                &$documents,
                &$seen,
                $baseUrl,
                $type,
            ): void {
                $url = $this->normalizeGeneralUrl((string) $anchor->attr('href'), $baseUrl);

                if ($url === null || isset($seen[$url])) {
                    return;
                }

                $seen[$url] = true;
                $name = $this->normalizeText($anchor->text(''));

                if ($name === '') {
                    $name = $this->documentNameFromUrl($url);
                }

                $documents[] = [
                    'type' => $type,
                    'name' => $name,
                    'url' => $url,
                ];
            });
        }

        return $documents;
    }

    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = $crawler->filter('meta[itemprop="brand"][content]')->first();

        if ($brand->count() === 0) {
            return null;
        }

        $value = $this->normalizeText((string) $brand->attr('content'));

        return $value !== '' ? $value : null;
    }

    private function metaContent(Crawler $crawler, string $name): ?string
    {
        $meta = $crawler->filter('meta[name="'.$name.'"][content]')->first();

        if ($meta->count() === 0) {
            return null;
        }

        $value = $this->normalizeText((string) $meta->attr('content'));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $specifications
     * @return array<string, string>
     */
    private function attributesFromSpecifications(array $specifications): array
    {
        $attributes = [];

        foreach ($specifications as $specification) {
            $label = (string) ($specification['label'] ?? '');
            $value = (string) ($specification['value'] ?? '');

            if ($label !== '' && $value !== '') {
                $attributes[$label] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<int, array<string, string>>  $documents
     */
    private function isMedicalDevice(string $html, array $documents): bool
    {
        $plainText = mb_strtolower($this->normalizeText(strip_tags($html)));

        if (str_contains($plainText, 'to jest wyrób medyczny')) {
            return true;
        }

        foreach ($documents as $document) {
            if (($document['type'] ?? '') === 'certificate' && str_contains(mb_strtolower($document['name'] ?? ''), 'ce')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $reference
     * @return array<int, array<int, string>>
     */
    private function categoryPaths(?array $context, ?array $reference): array
    {
        $paths = [];

        foreach (($context['category_paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }

            $normalized = array_values(array_filter(array_map(
                fn (mixed $segment): string => $this->normalizeText((string) $segment),
                $path,
            ), static fn (string $segment): bool => $segment !== ''));

            if ($normalized !== [] && ! in_array($normalized, $paths, true)) {
                $paths[] = $normalized;
            }
        }

        if ($paths !== [] || $reference === null) {
            return $paths;
        }

        $fallback = array_values(array_filter([
            $reference['product_group'] ?? null,
            $reference['sub_group'] ?? null,
            $reference['sub_sub_group'] ?? null,
        ], static fn (mixed $segment): bool => is_string($segment) && $segment !== ''));

        return $fallback === [] ? [] : [$fallback];
    }

    /**
     * @param  array<int, array<int, string>>  $categoryPaths
     */
    private function leafCategory(array $categoryPaths): ?string
    {
        $path = $categoryPaths[0] ?? null;

        if (! is_array($path) || $path === []) {
            return null;
        }

        $leaf = end($path);

        return is_string($leaf) && $leaf !== '' ? $leaf : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function productReference(string $url): ?array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        $sourceKey = rawurldecode($query);
        $selectedParts = explode('Selected', $sourceKey, 2);

        if (count($selectedParts) !== 2) {
            return null;
        }

        $categoryKey = $selectedParts[0];
        $selectedName = $this->normalizeText($selectedParts[1]);
        $groupPosition = strpos($categoryKey, 'ProductGroup');

        if ($groupPosition === false) {
            return null;
        }

        $categoryKey = substr($categoryKey, $groupPosition + strlen('ProductGroup'));
        $subGroupPosition = strpos($categoryKey, 'SubGroup');

        if ($subGroupPosition === false) {
            return null;
        }

        $productGroup = $this->normalizeText(substr($categoryKey, 0, $subGroupPosition));
        $remainder = substr($categoryKey, $subGroupPosition + strlen('SubGroup'));
        $subSubPosition = strpos($remainder, 'SubSubGroup');
        $subGroup = $subSubPosition === false
            ? $this->normalizeText($remainder)
            : $this->normalizeText(substr($remainder, 0, $subSubPosition));
        $subSubGroup = $subSubPosition === false
            ? ''
            : $this->normalizeText(substr($remainder, $subSubPosition + strlen('SubSubGroup')));

        return [
            'external_id' => hash('sha256', $sourceKey),
            'source_key' => $sourceKey,
            'product_group' => $productGroup,
            'sub_group' => $subGroup,
            'sub_sub_group' => $subSubGroup,
            'selected_name' => $selectedName,
        ];
    }

    private function firstTextByIdSuffix(Crawler $crawler, string $suffix): string
    {
        $node = $crawler->filterXPath(
            '//*[substring(@id, string-length(@id) - string-length("'.$suffix.'") + 1) = "'.$suffix.'"]'
        )->first();

        return $node->count() > 0 ? $this->normalizeText($node->text('')) : '';
    }

    private function innerHtml(Crawler $crawler): string
    {
        $node = $crawler->getNode(0);

        if (! $node instanceof DOMElement) {
            return '';
        }

        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }

    private function normalizeContentHtml(string $html, string $baseUrl): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="vermeiren-content-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (['script', 'style', 'form', 'iframe'] as $tagName) {
            $nodes = [];

            foreach ($document->getElementsByTagName($tagName) as $node) {
                $nodes[] = $node;
            }

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach ($document->getElementsByTagName('img') as $image) {
            $src = $this->normalizeAssetUrl($image->getAttribute('src'), $baseUrl);

            if ($src === null) {
                $image->removeAttribute('src');
            } else {
                $image->setAttribute('src', $src);
            }
        }

        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = $this->normalizeGeneralUrl($anchor->getAttribute('href'), $baseUrl);

            if ($href === null) {
                $anchor->removeAttribute('href');
            } else {
                $anchor->setAttribute('href', $href);
                $anchor->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $root = $document->getElementById('vermeiren-content-root');

        if (! $root instanceof DOMElement) {
            return trim($html);
        }

        $normalized = '';

        foreach ($root->childNodes as $child) {
            $normalized .= $document->saveHTML($child) ?: '';
        }

        return trim($normalized);
    }

    private function plainTextFromHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace(
            '#<(?:br\s*/?|/p|/div|/li|/tr|/td|/h[1-6])[^>]*>#iu',
            ' ',
            $html,
        ) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text !== '' ? $text : null;
    }

    private function normalizeVermeirenUrl(string $url, string $baseUrl): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/#.*$/', '', $url) ?? $url;

        if ($url === '' || $url === '#' || str_starts_with(strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::VERMEIREN_HOST.$url;
        } elseif (parse_url($url, PHP_URL_SCHEME) === null) {
            $basePath = parse_url($baseUrl, PHP_URL_PATH);
            $directory = is_string($basePath) ? rtrim(str_replace('\\', '/', dirname($basePath)), '/') : '/web/web.nsf';
            $url = 'https://'.self::VERMEIREN_HOST.$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['vermeiren.pl', self::VERMEIREN_HOST], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $encodedPath = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
        $normalized = 'https://'.self::VERMEIREN_HOST.($encodedPath === '' ? '/' : $encodedPath);
        $query = $parts['query'] ?? null;

        if (is_string($query) && $query !== '') {
            $normalized .= '?'.rawurlencode(rawurldecode($query));
        }

        return $normalized;
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $normalized = $this->normalizeGeneralUrl($url, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));

        if ($host === 'vermeiren.pl' || $host === self::VERMEIREN_HOST || str_ends_with($host, '.vermeiren.be')) {
            return $normalized;
        }

        return null;
    }

    private function normalizeGeneralUrl(string $url, string $baseUrl): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($url === '' || $url === '#' || str_starts_with(strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $scheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
            $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: self::VERMEIREN_HOST);
            $url = $scheme.'://'.$host.$url;
        } elseif (parse_url($url, PHP_URL_SCHEME) === null) {
            $basePath = (string) (parse_url($baseUrl, PHP_URL_PATH) ?: '/');
            $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
            $scheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
            $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: self::VERMEIREN_HOST);
            $url = $scheme.'://'.$host.$directory.'/'.$url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function imageFileKey(string $url): string
    {
        $name = mb_strtolower(rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))));
        $name = preg_replace('/^(?:web_|th_)+/i', '', $name) ?? $name;

        return $name !== '' ? $name : hash('sha256', $url);
    }

    private function assetName(string $url): string
    {
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $name = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name) ?? $name;
        $name = preg_replace('/^(?:web_|th_)+/i', '', $name) ?? $name;

        return $this->normalizeText(str_replace(['_', '-'], ' ', $name));
    }

    private function technicalSourceLabel(string $url): string
    {
        $label = mb_strtolower($this->assetName($url));
        $label = preg_replace('/^(scooter|wheelchair|beds?|tricycle|rollator|chair)\s+/i', '', $label) ?? $label;

        return $this->normalizeText($label);
    }

    private function technicalKey(string $label): string
    {
        $key = mb_strtolower($label);
        $key = preg_replace('/[^a-z0-9]+/u', '_', $key) ?? $key;

        return trim($key, '_');
    }

    private function technicalLabel(string $sourceLabel): string
    {
        return match (mb_strtolower($sourceLabel)) {
            'users weight' => 'Maksymalna waga użytkownika',
            'users weight no mattress' => 'Maksymalna waga użytkownika bez materaca',
            'total width' => 'Szerokość całkowita',
            'total length' => 'Długość całkowita',
            'total height' => 'Wysokość całkowita',
            'folded width' => 'Szerokość po złożeniu',
            'seat width' => 'Szerokość siedziska',
            'seat angle' => 'Kąt siedziska',
            'seat height' => 'Wysokość siedziska',
            'seat depth' => 'Głębokość siedziska',
            'backrest height' => 'Wysokość oparcia',
            'armrest height' => 'Wysokość podłokietnika',
            'distance footplate-seat', 'distance footplate to seat' => 'Odległość podnóżka od siedziska',
            'footplate height' => 'Wysokość podnóżka',
            'maximum inclination', 'inclination angle' => 'Maksymalne nachylenie',
            'obstacle height' => 'Wysokość pokonywanej przeszkody',
            'turning radius' => 'Promień skrętu',
            'total weight' => 'Waga całkowita',
            'speed' => 'Prędkość maksymalna',
            'batteries' => 'Akumulatory',
            'driving disctance', 'driving distance' => 'Zasięg',
            'power' => 'Moc',
            'min height' => 'Minimalna wysokość',
            'max height' => 'Maksymalna wysokość',
            'head adjustment' => 'Regulacja segmentu pleców',
            'leg adjustment' => 'Regulacja segmentu nóg',
            'knee adjustment' => 'Regulacja segmentu kolan',
            'antitrendelenburg' => 'Anty-Trendelenburg',
            default => ucfirst($sourceLabel),
        };
    }

    private function documentNameFromUrl(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $name = basename($path);

        if ($name !== '' && $name !== '/') {
            return $this->normalizeText($name);
        }

        return (string) parse_url($url, PHP_URL_HOST);
    }

    private function contextString(?array $context, string $key): ?string
    {
        if (! is_array($context) || ! is_string($context[$key] ?? null)) {
            return null;
        }

        $value = $this->normalizeText($context[$key]);

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
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if ($entry !== '' && ! in_array($entry, $strings, true)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopVermeirenProductCrawler/1.0)',
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

    private function emitRetry(string $url, int $attempt, string $reason): void
    {
        $this->emit(sprintf(
            'Retrying Vermeiren product URL after attempt %d/%d (%s): %s',
            $attempt,
            $this->maxAttempts,
            $reason,
            $url,
        ));
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
