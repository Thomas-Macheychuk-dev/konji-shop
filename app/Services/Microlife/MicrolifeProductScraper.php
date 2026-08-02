<?php

declare(strict_types=1);

namespace App\Services\Microlife;

use Closure;
use DOMElement;
use DOMNode;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MicrolifeProductScraper
{
    private const CANONICAL_HOST = 'www.microlife.pl';

    /**
     * @var array<string, string>
     */
    private const LEGACY_PATH_PREFIXES = [
        '/professional-products' => '/produkty-profesjonalne',
        '/consumer-products/flexible-heating/heating-pads' => '/produkty/termoterapia-2/poduszki-grzewcze',
        '/consumer-products/flexible-heating' => '/produkty/termoterapia-2',
    ];

    /**
     * @var array<int, string>
     */
    private const RELATED_SECTION_HEADINGS = [
        'podobne produkty',
        'powiazane produkty',
        'related products',
    ];

    /**
     * @var array<int, string>
     */
    private const DOWNLOAD_SECTION_HEADINGS = [
        'instrukcja obslugi',
        'do pobrania',
        'downloads',
    ];

    /**
     * @var array<int, string>
     */
    private const DESCRIPTION_SECTION_HEADINGS = [
        'funkcje',
        'wlasciwosci',
        'features',
        'cechy',
        'opis produktu',
        'product features',
    ];

    /**
     * @var array<int, string>
     */
    private const SPECIFICATION_SECTION_HEADINGS = [
        'specyfikacja',
        'specyfkacja',
        'specification',
        'specifications',
        'specyfikacje',
        'dane techniczne',
        'technical specifications',
        'technical data',
        'available models',
        'accessories',
        'akcesoria',
    ];

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

        $this->emit('Fetching Microlife product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch Microlife product page.';

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
        $catalogueType = $this->catalogueType($canonicalUrl, $productLinkContext);
        $seoTitle = $this->normalizeText($crawler->filter('title')->first()->text(''));
        $seoDescription = $this->firstMetaContent($crawler, 'description')
            ?? $this->firstMetaPropertyContent($crawler, 'og:description');
        $name = $this->extractName($crawler, $seoTitle, $productLinkContext);
        $headline = $this->extractHeadline($crawler, $name);
        $categoryData = $this->categoriesFromContext($productLinkContext);
        $descriptionItems = $this->extractSectionItems($crawler, self::DESCRIPTION_SECTION_HEADINGS);
        $specificationItems = $this->extractSectionItems($crawler, self::SPECIFICATION_SECTION_HEADINGS);
        $features = $this->extractFeatures($crawler, $sourceUrl);
        $descriptionItems = $this->descriptionItemsWithFallbacks(
            $crawler,
            $descriptionItems,
            $features,
            $seoDescription,
            $headline,
            $name,
        );

        if ($specificationItems === []) {
            $specificationItems = $this->extractSpecificationFallbackItems($crawler);
        }
        $attributes = $this->extractAttributes($specificationItems, $catalogueType);
        $downloads = $this->extractDownloads($crawler, $sourceUrl);
        $videos = $this->extractVideos($crawler, $sourceUrl);
        $relatedProducts = $this->extractRelatedProducts($crawler, $sourceUrl, $canonicalUrl);
        $images = $this->extractImages($crawler, $sourceUrl, includeFeatureImages: false);
        $featureImages = $this->extractImages($crawler, $sourceUrl, includeFeatureImages: true);
        $buyNowUrl = $this->extractBuyNowUrl($crawler, $sourceUrl);
        $productCode = $this->extractProductCode($name, $attributes, $productLinkContext);
        $variantCandidates = $this->variantCandidates(
            $canonicalUrl,
            $name,
            $catalogueType,
            $descriptionItems,
            $specificationItems,
        );
        $isMedicalDevice = $this->isMedicalDevice($crawler, $specificationItems);

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($descriptionItems === [] && $headline === null) {
            $warnings[] = 'Product description was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        return [
            'source' => 'microlife',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $this->externalProductId($canonicalUrl, $productLinkContext),
            'catalogue_type' => $catalogueType,
            'slug' => $this->slugFromUrl($canonicalUrl),
            'name' => $name,
            'product_code' => $productCode,
            'brand' => 'Microlife',
            'category' => $categoryData['category'],
            'categories' => $categoryData['categories'],
            'category_paths' => $categoryData['category_paths'],
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription,
            'headline' => $headline,
            'short_description' => $this->shortDescription($seoDescription, $headline, $descriptionItems),
            'description' => implode("\n", $descriptionItems),
            'description_html' => $this->paragraphsHtml($descriptionItems),
            'description_items' => $descriptionItems,
            'features' => $features,
            'specification_items' => $specificationItems,
            'attributes' => $attributes,
            'downloads' => $downloads,
            'videos' => $videos,
            'images' => $images,
            'feature_images' => $featureImages,
            'related_products' => $relatedProducts,
            'buy_now_url' => $buyNowUrl,
            'variant_candidates' => $variantCandidates,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => null,
            'ean' => null,
            'is_medical_device' => $isMedicalDevice,
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

        $path = '/'.trim(rawurldecode((string) parse_url($normalized, PHP_URL_PATH)), '/');
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if (
            count($segments) < 3
            || ! in_array($segments[0] ?? null, ['produkty', 'produkty-profesjonalne'], true)
            || $this->isNonProductPath($path)
        ) {
            return null;
        }

        return 'https://'.self::CANONICAL_HOST.$path;
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
        $canonicalUrl = $this->normalizeProductUrl($sourceUrl) ?? $sourceUrl;
        $categoryData = $this->categoriesFromContext($productLinkContext);

        return [
            'source' => 'microlife',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $this->externalProductId($canonicalUrl, $productLinkContext),
            'catalogue_type' => $this->catalogueType($canonicalUrl, $productLinkContext),
            'slug' => $this->slugFromUrl($canonicalUrl),
            'name' => '',
            'product_code' => null,
            'brand' => 'Microlife',
            'category' => $categoryData['category'],
            'categories' => $categoryData['categories'],
            'category_paths' => $categoryData['category_paths'],
            'seo_title' => null,
            'seo_description' => null,
            'headline' => null,
            'short_description' => null,
            'description' => '',
            'description_html' => null,
            'description_items' => [],
            'features' => [],
            'specification_items' => [],
            'attributes' => [],
            'downloads' => [],
            'videos' => [],
            'images' => [],
            'feature_images' => [],
            'related_products' => [],
            'buy_now_url' => null,
            'variant_candidates' => [],
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => null,
            'ean' => null,
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

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function extractName(Crawler $crawler, string $seoTitle, ?array $productLinkContext): string
    {
        foreach (['.product-number-type', '[property="producttype"]', '[property="product_number"]'] as $selector) {
            $nodes = $crawler->filter($selector);

            if ($nodes->count() === 0) {
                continue;
            }

            $name = $this->normalizeText($nodes->first()->text(''));

            if ($name !== '') {
                return $name;
            }
        }

        $contextName = $this->contextString($productLinkContext, 'name');

        if ($contextName !== null) {
            return $contextName;
        }

        foreach ([$this->firstMetaPropertyContent($crawler, 'og:title'), $seoTitle] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = preg_replace('/\s+-\s+Microlife(?:\s+AG)?\s*$/iu', '', $this->normalizeText($candidate)) ?? $candidate;

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function extractHeadline(Crawler $crawler, string $name): ?string
    {
        foreach (['[property="pagetitle"]', '.headline-product', '.product-detail h1', 'main h1'] as $selector) {
            $nodes = $crawler->filter($selector);

            if ($nodes->count() === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $headline = $this->normalizeText($node->textContent);

                if ($headline !== '' && $headline !== $name && ! $this->isSectionHeading($headline)) {
                    return $headline;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $headingNames
     * @return array<int, string>
     */
    private function extractSectionItems(Crawler $crawler, array $headingNames): array
    {
        $items = [];

        foreach ($crawler->filter('h1, h2, h3, h4, [class*="headline"]') as $headingNode) {
            if (! $headingNode instanceof DOMElement) {
                continue;
            }

            $heading = $this->normalizeKey($headingNode->textContent);

            if (! in_array($heading, $headingNames, true)) {
                continue;
            }

            foreach ($this->nodesUntilNextHeading($headingNode) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                if (in_array(strtolower($node->tagName), ['p', 'li'], true)) {
                    $text = $this->normalizeText($node->textContent);

                    if ($this->isUsefulContentText($text)) {
                        $items[$text] = true;
                    }
                }

                foreach ($node->getElementsByTagName('p') as $paragraph) {
                    $text = $this->normalizeText($paragraph->textContent);

                    if ($this->isUsefulContentText($text)) {
                        $items[$text] = true;
                    }
                }

                foreach ($node->getElementsByTagName('li') as $listItem) {
                    $text = $this->normalizeText($listItem->textContent);

                    if ($this->isUsefulContentText($text)) {
                        $items[$text] = true;
                    }
                }
            }
        }

        return array_keys($items);
    }

    /**
     * @return array<int, DOMNode>
     */
    private function nodesUntilNextHeading(DOMElement $heading): array
    {
        $nodes = [];
        $node = $heading->nextSibling;

        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['h1', 'h2'], true)) {
                break;
            }

            $nodes[] = $node;
            $node = $node->nextSibling;
        }

        if ($nodes !== []) {
            return $nodes;
        }

        $container = $heading->parentNode;

        if (! $container instanceof DOMElement) {
            return [];
        }

        $node = $container->nextSibling;

        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement && $this->containsMajorHeading($node)) {
                break;
            }

            $nodes[] = $node;
            $node = $node->nextSibling;
        }

        return $nodes;
    }

    private function containsMajorHeading(DOMElement $element): bool
    {
        if (in_array(strtolower($element->tagName), ['h1', 'h2'], true)) {
            return true;
        }

        return $element->getElementsByTagName('h1')->length > 0
            || $element->getElementsByTagName('h2')->length > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractFeatures(Crawler $crawler, string $baseUrl): array
    {
        $features = [];

        foreach (['.product-feature', '.product-feature-item', '.product-benefit', '[class*="feature"]', '[class*="benefit"]', '[class*="advantage"]'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$features, $baseUrl): void {
                    $title = '';

                    foreach (['h2', 'h3', 'h4', '.headline', 'strong'] as $titleSelector) {
                        $candidate = $this->normalizeText($node->filter($titleSelector)->first()->text(''));

                        if ($candidate !== '' && ! $this->isSectionHeading($candidate)) {
                            $title = $candidate;
                            break;
                        }
                    }

                    if ($title === '') {
                        return;
                    }

                    $description = $this->normalizeText($node->filter('p')->first()->text(''));
                    $imageUrl = null;
                    $image = $node->filter('img[src], img[data-src], img[data-lazy-src]')->first();

                    if ($image->count() > 0) {
                        foreach (['data-src', 'data-lazy-src', 'src'] as $attribute) {
                            $candidate = $image->attr($attribute);

                            if (is_string($candidate) && trim($candidate) !== '') {
                                $imageUrl = $this->normalizeAssetUrl($candidate, $baseUrl);
                                break;
                            }
                        }
                    }

                    $key = mb_strtolower($title.'|'.($description ?? ''));
                    $features[$key] = [
                        'title' => $title,
                        'description' => $description !== '' ? $description : null,
                        'image_url' => $imageUrl,
                    ];
                });
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($this->featureSectionCandidates($crawler) as $node) {
            $feature = $this->featureFromNode($node, $baseUrl);

            if ($feature === null) {
                continue;
            }

            $key = mb_strtolower(
                $feature['title'].'|'.($feature['description'] ?? ''),
            );

            $features[$key] = $feature;
        }

        return array_values($features);
    }

    /**
     * @return array<int, DOMElement>
     */
    private function featureSectionCandidates(Crawler $crawler): array
    {
        $candidates = [];

        foreach ($crawler->filter('h1, h2, h3, h4, [class*="headline"]') as $headingNode) {
            if (! $headingNode instanceof DOMElement) {
                continue;
            }

            if (! in_array(
                $this->normalizeKey($headingNode->textContent),
                self::DESCRIPTION_SECTION_HEADINGS,
                true,
            )) {
                continue;
            }

            foreach ($this->nodesUntilNextHeading($headingNode) as $sectionNode) {
                if (! $sectionNode instanceof DOMElement) {
                    continue;
                }

                $sectionCrawler = new Crawler($sectionNode);

                foreach ($sectionCrawler->filter('article, li, div') as $candidate) {
                    if (! $candidate instanceof DOMElement) {
                        continue;
                    }

                    $candidateCrawler = new Crawler($candidate);

                    if ($candidateCrawler->filter('img')->count() !== 1) {
                        continue;
                    }

                    $candidates[spl_object_id($candidate)] = $candidate;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * @return array{title: string, description: string|null, image_url: string|null}|null
     */
    private function featureFromNode(DOMElement $element, string $baseUrl): ?array
    {
        $node = new Crawler($element);
        $title = '';

        foreach ([
            'h2',
            'h3',
            'h4',
            '[class*="headline"]',
            '[class*="title"]',
            '[property*="title"]',
            '.medium',
            '.big',
            'strong',
            'span',
        ] as $selector) {
            try {
                foreach ($node->filter($selector) as $titleNode) {
                    if (! $titleNode instanceof DOMElement) {
                        continue;
                    }

                    $candidate = $this->normalizeText($titleNode->textContent);

                    if (
                        $candidate === ''
                        || mb_strlen($candidate) > 160
                        || $this->isSectionHeading($candidate)
                    ) {
                        continue;
                    }

                    $title = $candidate;
                    break 2;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $image = $node->filter('img')->first();
        $imageUrl = null;
        $alt = '';

        if ($image->count() > 0) {
            $imageElement = $image->getNode(0);

            if ($imageElement instanceof DOMElement) {
                $imageUrl = $this->imageUrl($imageElement, $baseUrl);
                $alt = $this->normalizeText($imageElement->getAttribute('alt'));
            }
        }

        if ($title === '' && $alt !== '' && ! str_contains($this->normalizeKey($alt), 'icon')) {
            $title = $alt;
        }

        if ($title === '') {
            return null;
        }

        $description = null;

        foreach ($node->filter('p') as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }

            $candidate = $this->normalizeText($paragraph->textContent);

            if (
                $candidate !== ''
                && $candidate !== $title
                && $this->isUsefulContentText($candidate)
            ) {
                $description = $candidate;
                break;
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @param  array<int, string>  $descriptionItems
     * @param  array<int, array<string, mixed>>  $features
     * @return array<int, string>
     */
    private function descriptionItemsWithFallbacks(
        Crawler $crawler,
        array $descriptionItems,
        array $features,
        ?string $seoDescription,
        ?string $headline,
        string $name,
    ): array {
        $items = [];

        foreach ($descriptionItems as $item) {
            if ($this->isUsefulContentText($item)) {
                $items[$item] = true;
            }
        }

        if ($items === []) {
            foreach ([
                '.product-features p',
                '.product-parts p',
                '.product-characteristics p',
                '.product-info p',
                '.product-header-inner p',
            ] as $selector) {
                try {
                    foreach ($crawler->filter($selector) as $paragraph) {
                        if (! $paragraph instanceof DOMElement) {
                            continue;
                        }

                        $text = $this->normalizeText($paragraph->textContent);

                        if (
                            $this->isUsefulContentText($text)
                            && ! $this->hasAncestorClassFragment($paragraph, [
                                'related',
                                'support',
                                'clinical',
                                'internal-link',
                                'news',
                                'navigation',
                            ])
                            && ! $this->hasAncestorTag($paragraph, 'footer')
                        ) {
                            $items[$text] = true;
                        }
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        if ($items === []) {
            foreach ($features as $feature) {
                foreach (['description', 'title'] as $field) {
                    $candidate = $feature[$field] ?? null;

                    if (! is_string($candidate)) {
                        continue;
                    }

                    $candidate = $this->normalizeText($candidate);

                    if (
                        $candidate !== ''
                        && $candidate !== $name
                        && $this->isUsefulContentText($candidate)
                    ) {
                        $items[$candidate] = true;
                    }
                }
            }
        }

        foreach ([$seoDescription, $headline] as $candidate) {
            if ($items !== [] || ! is_string($candidate)) {
                continue;
            }

            $candidate = $this->normalizeText($candidate);

            if (
                $candidate !== ''
                && $candidate !== $name
                && $this->isUsefulContentText($candidate)
            ) {
                $items[$candidate] = true;
            }
        }

        return array_keys($items);
    }

    /**
     * @return array<int, string>
     */
    private function extractSpecificationFallbackItems(Crawler $crawler): array
    {
        $items = [];

        foreach ($crawler->filter('table') as $table) {
            if (
                ! $table instanceof DOMElement
                || $this->hasAncestorClassFragment($table, [
                    'related',
                    'support',
                    'clinical',
                    'internal-link',
                    'news',
                    'navigation',
                ])
                || $this->hasAncestorTag($table, 'footer')
            ) {
                continue;
            }

            $tableCrawler = new Crawler($table);

            foreach ($tableCrawler->filter('tr') as $row) {
                if (! $row instanceof DOMElement) {
                    continue;
                }

                $cells = [];
                $rowCrawler = new Crawler($row);

                foreach ($rowCrawler->filter('th, td') as $cell) {
                    if (! $cell instanceof DOMElement) {
                        continue;
                    }

                    $text = $this->normalizeText($cell->textContent);

                    if ($text !== '') {
                        $cells[] = $text;
                    }
                }

                if (count($cells) < 2) {
                    continue;
                }

                $item = count($cells) === 2
                    ? $cells[0].': '.$cells[1]
                    : implode(' | ', $cells);

                if ($this->isUsefulContentText($item)) {
                    $items[$item] = true;
                }
            }
        }

        if ($items === []) {
            foreach ($crawler->filter('li') as $listItem) {
                if (
                    ! $listItem instanceof DOMElement
                    || $this->hasAncestorClassFragment($listItem, [
                        'related',
                        'support',
                        'clinical',
                        'internal-link',
                        'news',
                        'navigation',
                    ])
                    || $this->hasAncestorTag($listItem, 'footer')
                ) {
                    continue;
                }

                $text = $this->normalizeText($listItem->textContent);

                if ($this->isUsefulContentText($text)) {
                    $items[$text] = true;
                }
            }
        }

        return array_keys($items);
    }

    /**
     * @param  array<int, string>  $specificationItems
     * @return array<int, array{code: string, label: string, value: string, slug: string|null}>
     */
    private function extractAttributes(array $specificationItems, string $catalogueType): array
    {
        $attributes = [];

        foreach ($specificationItems as $item) {
            if (preg_match('/^([^:]{2,80}):\s*(.+)$/u', $item, $matches) !== 1) {
                continue;
            }

            $label = $this->normalizeText($matches[1]);
            $value = $this->normalizeText($matches[2]);

            if ($label === '' || $value === '') {
                continue;
            }

            $code = Str::of($label)->lower()->ascii()->slug('-')->value();
            $attributes[$code] = [
                'code' => $code,
                'label' => $label,
                'value' => $value,
                'slug' => $this->slugify($value),
            ];
        }

        $attributes['typ-katalogu'] = [
            'code' => 'typ-katalogu',
            'label' => 'Typ katalogu',
            'value' => $catalogueType === 'professional' ? 'Profesjonalny' : 'Konsumencki',
            'slug' => $catalogueType,
        ];

        return array_values($attributes);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractDownloads(Crawler $crawler, string $baseUrl): array
    {
        $downloads = [];

        foreach ($this->sectionElements($crawler, self::DOWNLOAD_SECTION_HEADINGS, 'a[href]') as $anchor) {
            $href = $this->normalizeAssetUrl($anchor->getAttribute('href'), $baseUrl);

            if ($href === null) {
                continue;
            }

            $text = $this->normalizeText($anchor->textContent);
            $context = $this->normalizeText($anchor->parentNode?->textContent ?? '');
            $extension = strtolower((string) pathinfo((string) parse_url($href, PHP_URL_PATH), PATHINFO_EXTENSION));
            $isDownload = in_array($extension, ['pdf', 'exe', 'zip', 'dmg', 'pkg', 'msi'], true)
                || preg_match('/\b(PDF|EXE|ZIP|DMG|MSI)\b/iu', $context) === 1;

            if (! $isDownload || $text === '') {
                continue;
            }

            $fileType = $extension !== '' ? strtoupper($extension) : $this->fileTypeFromText($context);
            $size = null;

            if (preg_match('/\(([0-9]+(?:[.,][0-9]+)?\s*(?:KB|MB|GB))\)/iu', $context, $matches) === 1) {
                $size = $this->normalizeText($matches[1]);
            }

            $downloads[$href] = [
                'name' => $text,
                'url' => $href,
                'file_type' => $fileType,
                'file_size' => $size,
            ];
        }

        return array_values($downloads);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractVideos(Crawler $crawler, string $baseUrl): array
    {
        $videos = [];

        foreach ($crawler->filter('iframe[src], a[href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $candidate = $node->hasAttribute('src') ? $node->getAttribute('src') : $node->getAttribute('href');
            $url = $this->normalizeAssetUrl($candidate, $baseUrl);

            if ($url === null || preg_match('#(?:youtube\.com|youtu\.be|vimeo\.com)#iu', $url) !== 1) {
                continue;
            }

            $title = $this->normalizeText($node->getAttribute('title'));

            if ($title === '') {
                $title = 'Wideo produktowe';
            }

            $videos[$url] = [
                'title' => $title,
                'url' => $url,
            ];
        }

        return array_values($videos);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRelatedProducts(Crawler $crawler, string $baseUrl, string $canonicalUrl): array
    {
        $related = [];

        foreach ($this->sectionElements($crawler, self::RELATED_SECTION_HEADINGS, 'a[href]') as $anchor) {
            $url = $this->normalizeProductUrl($anchor->getAttribute('href'), $baseUrl);

            if ($url === null || $url === $canonicalUrl) {
                continue;
            }

            $name = $this->normalizeText($anchor->textContent);

            if ($name === '' || in_array($this->normalizeKey($name), ['pokaz produkt', 'view product'], true)) {
                $name = $this->humanizeSlug($this->slugFromUrl($url));
            }

            if (! isset($related[$url]) || mb_strlen($name) > mb_strlen((string) $related[$url]['name'])) {
                $related[$url] = [
                    'name' => $name,
                    'url' => $url,
                    'external_id' => hash('sha256', $url),
                ];
            }
        }

        return array_values($related);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractImages(Crawler $crawler, string $baseUrl, bool $includeFeatureImages): array
    {
        $images = [];
        $position = 0;

        foreach ($crawler->filter('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $url = $this->imageUrl($image, $baseUrl);

            if (
                $url === null
                || ! str_contains((string) parse_url($url, PHP_URL_PATH), '/uploads/media/')
                || $this->hasAncestorClassFragment($image, [
                    'related',
                    'support',
                    'clinical',
                    'internal-link',
                    'news',
                    'navigation',
                ])
                || $this->hasAncestorTag($image, 'footer')
            ) {
                continue;
            }

            $class = mb_strtolower($image->getAttribute('class'));
            $alt = $this->normalizeText($image->getAttribute('alt'));

            if ($this->isApplicationStoreBadge($url, $alt, $class)) {
                continue;
            }

            $isProductMedia = $this->isProductMediaImage($image);
            $isFeature = ! $isProductMedia && (
                str_contains($class, 'icon')
                || str_contains(mb_strtolower($url), '/icon')
                || str_contains(mb_strtolower($alt), 'icon')
                || str_contains(mb_strtolower($url), 'youtube')
                || str_contains(mb_strtolower($url), 'laptop')
                || str_contains(mb_strtolower($url), '/flows/')
                || $this->hasAncestorClassFragment($image, [
                    'feature',
                    'benefit',
                    'advantage',
                    'function',
                    'technology',
                ])
            );

            if ($includeFeatureImages !== $isFeature) {
                continue;
            }

            $images[$url] = [
                'url' => $url,
                'source_url' => $url,
                'alt' => $alt !== '' ? $alt : null,
                'position' => $position++,
                'role' => $includeFeatureImages ? 'feature' : ($position === 1 ? 'primary' : 'gallery'),
            ];
        }

        if (! $includeFeatureImages) {
            foreach ($crawler->filter('[style*="background-image"]') as $element) {
                if (
                    ! $element instanceof DOMElement
                    || ! $this->isProductBackgroundImageElement($element)
                    || $this->hasAncestorClassFragment($element, [
                        'related',
                        'support',
                        'clinical',
                        'internal-link',
                        'news',
                        'navigation',
                    ])
                    || $this->hasAncestorTag($element, 'footer')
                ) {
                    continue;
                }

                $url = $this->backgroundImageUrl($element, $baseUrl);

                if (
                    $url === null
                    || ! str_contains((string) parse_url($url, PHP_URL_PATH), '/uploads/media/')
                    || isset($images[$url])
                ) {
                    continue;
                }

                $images[$url] = [
                    'url' => $url,
                    'source_url' => $url,
                    'alt' => null,
                    'position' => $position,
                    'role' => $position === 0 ? 'primary' : 'gallery',
                ];
                $position++;
            }
        }

        if ($images === [] && ! $includeFeatureImages) {
            foreach ([
                ['meta[property="og:image"]', 'content'],
                ['meta[name="twitter:image"]', 'content'],
                ['meta[name="image"]', 'content'],
            ] as [$selector, $attribute]) {
                $candidate = $this->firstAttr($crawler, $selector, $attribute);
                $url = is_string($candidate)
                    ? $this->normalizeAssetUrl($candidate, $baseUrl)
                    : null;

                if (
                    $url === null
                    || ! str_contains((string) parse_url($url, PHP_URL_PATH), '/uploads/media/')
                ) {
                    continue;
                }

                $images[$url] = [
                    'url' => $url,
                    'source_url' => $url,
                    'alt' => null,
                    'position' => 0,
                    'role' => 'primary',
                ];

                break;
            }
        }

        return array_values($images);
    }

    private function isProductMediaImage(DOMElement $image): bool
    {
        $class = mb_strtolower($image->getAttribute('class'));

        return str_contains($class, 'js-product-image')
            || $this->hasAncestorClassFragment($image, ['product-features-image']);
    }

    private function isProductBackgroundImageElement(DOMElement $element): bool
    {
        $class = mb_strtolower($element->getAttribute('class'));
        $property = $this->normalizeKey($element->getAttribute('property'));

        return str_contains($class, 'product-parts-image')
            || $property === 'product parts image';
    }

    private function backgroundImageUrl(DOMElement $element, string $baseUrl): ?string
    {
        $style = $element->getAttribute('style');

        if (preg_match('/background-image\s*:\s*url\(\s*(["\']?)(.*?)\1\s*\)/iu', $style, $matches) !== 1) {
            return null;
        }

        return $this->normalizeAssetUrl(trim($matches[2]), $baseUrl);
    }

    private function isApplicationStoreBadge(string $url, string $alt, string $class): bool
    {
        $key = $this->normalizeKey($url.' '.$alt.' '.$class);

        foreach ([
            'app store',
            'appstore',
            'google play',
            'play badge',
        ] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function imageUrl(DOMElement $image, string $baseUrl): ?string
    {
        foreach ([
            'data-src',
            'data-lazy-src',
            'data-original',
            'data-original-src',
            'data-image',
            'data-lazy',
            'data-lazy-srcset',
            'data-srcset',
            'srcset',
            'src',
        ] as $attribute) {
            $value = trim($image->getAttribute($attribute));

            if ($value === '') {
                continue;
            }

            if (str_contains($attribute, 'srcset')) {
                $sources = array_values(array_filter(array_map(
                    static fn (string $source): string => trim((string) preg_replace('/\s+\d+(?:\.\d+)?[wx]$/u', '', trim($source))),
                    explode(',', $value),
                )));

                $value = (string) ($sources[array_key_last($sources)] ?? '');
            }

            $url = $this->normalizeAssetUrl($value, $baseUrl);

            if ($url !== null) {
                return $url;
            }
        }

        $picture = $image->parentNode;

        if (
            $picture instanceof DOMElement
            && mb_strtolower($picture->tagName) === 'picture'
        ) {
            foreach ($picture->getElementsByTagName('source') as $source) {
                foreach ([
                    'data-lazy-srcset',
                    'data-srcset',
                    'srcset',
                    'data-src',
                    'src',
                ] as $attribute) {
                    $value = trim($source->getAttribute($attribute));

                    if ($value === '') {
                        continue;
                    }

                    if (str_contains($attribute, 'srcset')) {
                        $sources = array_values(array_filter(array_map(
                            static fn (string $candidate): string => trim((string) preg_replace(
                                '/\s+\d+(?:\.\d+)?[wx]$/u',
                                '',
                                trim($candidate),
                            )),
                            explode(',', $value),
                        )));

                        $value = (string) ($sources[array_key_last($sources)] ?? '');
                    }

                    $url = $this->normalizeAssetUrl($value, $baseUrl);

                    if ($url !== null) {
                        return $url;
                    }
                }
            }
        }

        return null;
    }

    private function extractBuyNowUrl(Crawler $crawler, string $baseUrl): ?string
    {
        foreach ($crawler->filter('a[href]') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            if ($this->normalizeKey($anchor->textContent) !== 'kup teraz') {
                continue;
            }

            return $this->normalizeAssetUrl($anchor->getAttribute('href'), $baseUrl);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $descriptionItems
     * @param  array<int, string>  $specificationItems
     * @return array<int, array<string, mixed>>
     */
    private function variantCandidates(
        string $canonicalUrl,
        string $name,
        string $catalogueType,
        array $descriptionItems,
        array $specificationItems,
    ): array {
        if (
            $catalogueType !== 'professional'
            || ! str_contains((string) parse_url($canonicalUrl, PHP_URL_PATH), '/mankiety-i-wyposazenie/')
        ) {
            return [];
        }

        $text = implode("\n", [...$descriptionItems, ...$specificationItems]);
        $sizes = [];

        if (preg_match_all(
            '/(?:rozmiar(?:ze|ach|u)?\s+)([A-Z][A-Z0-9-]{0,5})\s*\(([^)]+)\)/u',
            $text,
            $matches,
            PREG_SET_ORDER,
        ) > 0) {
            foreach ($matches as $match) {
                $size = $this->normalizeText($match[1]);
                $measurement = $this->normalizeText($match[2]);

                if ($size !== '' && $measurement !== '') {
                    $sizes[$size] = $measurement;
                }
            }
        }

        if (count($sizes) < 2) {
            return [];
        }

        $variants = [];

        foreach ($sizes as $size => $measurement) {
            $variants[] = [
                'external_variant_id' => hash('sha256', $canonicalUrl.'|size|'.mb_strtolower($size)),
                'sku' => null,
                'product_code' => null,
                'size' => $size,
                'color' => null,
                'model_code' => null,
                'name' => $name.' – '.$size,
                'option_values' => [
                    [
                        'attribute' => 'Rozmiar',
                        'value' => $size,
                    ],
                ],
                'measurements' => ['Obwód' => $measurement],
                'measurement_label' => 'Obwód',
                'measurement' => $measurement,
                'price_gross_amount' => null,
                'currency' => 'PLN',
                'availability' => 'unknown',
                'stock_quantity' => null,
            ];
        }

        return $variants;
    }

    /**
     * @param  array<int, string>  $specificationItems
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function extractProductCode(string $name, array $attributes, ?array $productLinkContext): ?string
    {
        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if (in_array($attribute['code'] ?? null, ['model', 'mod-no', 'model-no'], true)) {
                return $this->normalizeText((string) ($attribute['value'] ?? '')) ?: null;
            }
        }

        $contextCode = $this->contextString($productLinkContext, 'product_code');

        if ($contextCode !== null) {
            return $contextCode;
        }

        if (preg_match('/\b((?:BP|NC|MT|NEB|OXY|PF|BC|FH|WatchBP)\s*[A-Z0-9][A-Z0-9 .+-]*)$/iu', $name, $matches) === 1) {
            return $this->normalizeText($matches[1]);
        }

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<int, string>  $specificationItems
     */
    private function isMedicalDevice(Crawler $crawler, array $specificationItems): bool
    {
        $text = mb_strtolower($this->normalizeText($crawler->filter('body')->text('')));

        if (str_contains($text, 'to jest wyrób medyczny') || str_contains($text, 'wyrob medyczny')) {
            return true;
        }

        foreach ($specificationItems as $item) {
            if (str_contains($this->normalizeKey($item), 'wyrob medyczny')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function catalogueType(string $url, ?array $productLinkContext): string
    {
        $context = $this->contextString($productLinkContext, 'catalogue_type');

        if (in_array($context, ['consumer', 'professional'], true)) {
            return $context;
        }

        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/produkty-profesjonalne/')
            ? 'professional'
            : 'consumer';
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
     * @param  array<string, mixed>|null  $productLinkContext
     */
    private function externalProductId(string $canonicalUrl, ?array $productLinkContext): string
    {
        return $this->contextString($productLinkContext, 'external_id') ?? hash('sha256', $canonicalUrl);
    }

    /**
     * @param  array<int, string>  $descriptionItems
     */
    private function shortDescription(?string $seoDescription, ?string $headline, array $descriptionItems): ?string
    {
        foreach ([$seoDescription, $headline, $descriptionItems[0] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->normalizeText($candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function paragraphsHtml(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        return implode('', array_map(
            static fn (string $item): string => '<p>'.htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>',
            $items,
        ));
    }

    /**
     * @param  array<int, string>  $headingNames
     * @return array<int, DOMElement>
     */
    private function sectionElements(Crawler $crawler, array $headingNames, string $selector): array
    {
        $elements = [];

        foreach ($crawler->filter('h1, h2, h3, h4, [class*="headline"]') as $headingNode) {
            if (! $headingNode instanceof DOMElement) {
                continue;
            }

            if (! in_array($this->normalizeKey($headingNode->textContent), $headingNames, true)) {
                continue;
            }

            foreach ($this->nodesUntilNextHeading($headingNode) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $nodeCrawler = new Crawler($node);

                if ($selector === 'a[href]' && strtolower($node->tagName) === 'a' && $node->hasAttribute('href')) {
                    $elements[spl_object_id($node)] = $node;
                }

                try {
                    foreach ($nodeCrawler->filter($selector) as $element) {
                        if ($element instanceof DOMElement) {
                            $elements[spl_object_id($element)] = $element;
                        }
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return array_values($elements);
    }

    /**
     * @param  array<int, string>  $fragments
     */
    private function hasAncestorClassFragment(DOMElement $element, array $fragments): bool
    {
        $node = $element->parentNode;

        for ($depth = 0; $depth < 8 && $node instanceof DOMNode; $depth++, $node = $node->parentNode) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $class = mb_strtolower($node->getAttribute('class'));

            foreach ($fragments as $fragment) {
                if ($class !== '' && str_contains($class, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasAncestorTag(DOMElement $element, string $tagName): bool
    {
        $node = $element->parentNode;
        $tagName = mb_strtolower($tagName);

        for ($depth = 0; $depth < 8 && $node instanceof DOMNode; $depth++, $node = $node->parentNode) {
            if (
                $node instanceof DOMElement
                && mb_strtolower($node->tagName) === $tagName
            ) {
                return true;
            }
        }

        return false;
    }

    private function fileTypeFromText(string $text): ?string
    {
        if (preg_match('/\b(PDF|EXE|ZIP|DMG|MSI)\b/iu', $text, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function isUsefulContentText(string $text): bool
    {
        if ($text === '' || mb_strlen($text) < 3) {
            return false;
        }

        return ! in_array($this->normalizeKey($text), [
            'kup teraz',
            'pokaz produkt',
            'view product',
            'przeczytaj wiecej',
            'read more',
        ], true);
    }

    private function isSectionHeading(string $text): bool
    {
        $key = $this->normalizeKey($text);

        return in_array($key, [
            ...self::DESCRIPTION_SECTION_HEADINGS,
            ...self::SPECIFICATION_SECTION_HEADINGS,
            ...self::DOWNLOAD_SECTION_HEADINGS,
            ...self::RELATED_SECTION_HEADINGS,
            'wideo produktowe',
            'wsparcie oprogramowanie i instrukcje obslugi',
            'related clinical studies',
            'powiazanie badania kliniczne',
        ], true);
    }

    private function normalizeKey(string $text): string
    {
        return Str::of($this->normalizeText($text))->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }

    private function humanizeSlug(string $slug): string
    {
        return Str::of(rawurldecode($slug))->replace(['-', '_'], ' ')->squish()->title()->value();
    }

    private function slugFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        return (string) (end($segments) ?: hash('sha256', $url));
    }

    private function slugify(string $value): ?string
    {
        $slug = Str::of($value)->lower()->ascii()->slug('-')->value();

        return $slug !== '' ? $slug : null;
    }

    private function isNonProductPath(string $path): bool
    {
        return str_contains($path, '/walidacje-i-badania-kliniczne/')
            || str_contains($path, '/oprogramowanie')
            || str_contains($path, '/wyszukiwarka-produktow');
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::CANONICAL_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $url = rtrim($baseUrl, '/').'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['microlife.pl', self::CANONICAL_HOST], true)) {
            return null;
        }

        $path = rawurldecode((string) ($parts['path'] ?? '/'));

        foreach (self::LEGACY_PATH_PREFIXES as $legacyPrefix => $canonicalPrefix) {
            if ($path === $legacyPrefix || str_starts_with($path, $legacyPrefix.'/')) {
                $path = $canonicalPrefix.substr($path, strlen($legacyPrefix));
                break;
            }
        }

        $path = '/'.trim($path, '/');

        return 'https://'.self::CANONICAL_HOST.$path;
    }

    private function normalizeAssetUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return 'https://'.self::CANONICAL_HOST.$url;
        }

        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        return 'https://'.self::CANONICAL_HOST.$directory.'/'.ltrim($url, '/');
    }

    private function firstMetaContent(Crawler $crawler, string $name): ?string
    {
        $value = $this->firstAttr($crawler, 'meta[name="'.$name.'"]', 'content');

        return is_string($value) && trim($value) !== '' ? $this->normalizeText($value) : null;
    }

    private function firstMetaPropertyContent(Crawler $crawler, string $property): ?string
    {
        $value = $this->firstAttr($crawler, 'meta[property="'.$property.'"]', 'content');

        return is_string($value) && trim($value) !== '' ? $this->normalizeText($value) : null;
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $nodes = $crawler->filter($selector);

            if ($nodes->count() === 0) {
                return null;
            }

            $value = $nodes->first()->attr($attribute);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function contextString(?array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = $this->normalizeText((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{FFFC}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
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

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopMicrolifeCrawler/1.0; +https://konji.pl)',
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
