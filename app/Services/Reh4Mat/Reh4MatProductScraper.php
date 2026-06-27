<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

final class Reh4MatProductScraper
{
    private const REH4MAT_HOST = 'www.reh4mat.com';

    /** @var array<int, string> */
    private const ALLOWED_ASSET_HOSTS = [
        'www.reh4mat.com',
        'reh4mat.com',
        'stabilobedsystem.pl',
        'www.stabilobedsystem.pl',
        'bodymapsystem.pl',
        'www.bodymapsystem.pl',
        'bodymapsystem.com',
        'www.bodymapsystem.com',
        'ru.bodymapsystem.com',
    ];

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

        $this->emit('Fetching Reh4Mat product page: '.$sourceUrl);
        $html = $this->fetchBody($sourceUrl, $failed);

        if ($html === null) {
            $warnings[] = 'Unable to fetch Reh4Mat product page.';

            return $this->emptyResult($sourceUrl, $failed, $warnings);
        }

        $xpath = $this->xpath($html);

        if (! $xpath instanceof DOMXPath) {
            $warnings[] = 'Unable to parse Reh4Mat product HTML.';

            return $this->emptyResult($sourceUrl, $failed, $warnings);
        }

        $canonicalUrl = $this->extractCanonicalUrl($xpath, $sourceUrl) ?? $sourceUrl;
        $slug = $this->slugFromUrl($canonicalUrl);
        $name = $this->extractProductName($xpath);
        $seoTitle = $this->extractSeoTitle($xpath);
        $seoDescription = $this->extractMetaContent($xpath, 'description');
        $externalProductId = $this->extractExternalProductId($xpath, $html) ?? $slug;
        $productMeta = $this->extractProductMeta($xpath);
        $brand = $this->extractBrand($xpath, $html);
        $codes = $this->extractCodes($xpath, $html);
        $categories = $this->extractBreadcrumbCategories($xpath, $name);
        $tabs = $this->extractTabs($xpath, $canonicalUrl);
        $descriptionHtml = $this->extractDescriptionHtml($xpath, $tabs);
        $shortDescription = $this->extractShortDescription($seoDescription, $descriptionHtml);
        $images = $this->extractImages($xpath, $canonicalUrl, $name);
        $pictograms = $this->extractPictograms($xpath, $canonicalUrl);
        $regulatoryIcons = $this->extractRegulatoryIcons($xpath, $canonicalUrl);
        $downloads = $this->extractDownloads($xpath, $canonicalUrl);
        $medicalDeviceNotice = $this->extractMedicalDeviceNotice($xpath, $html);
        $isMedicalDevice = $medicalDeviceNotice !== null || $regulatoryIcons !== [] || $this->containsMedicalDeviceNotice($html);

        if ($name === '') {
            $warnings[] = 'Product name was not found.';
        }

        if ($images === []) {
            $warnings[] = 'Product images were not found.';
        }

        return [
            'source' => 'reh4mat',
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
            'short_description' => $shortDescription,
            'description' => $this->htmlToText($descriptionHtml),
            'description_html' => $descriptionHtml,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => $this->skuFromProductMeta($productMeta) ?? $this->skuFromName($name),
            'ean' => null,
            'product_meta' => $productMeta,
            'codes' => $codes,
            'images' => $images,
            'pictograms' => $pictograms,
            'regulatory_icons' => $regulatoryIcons,
            'downloads' => $downloads,
            'tabs' => $tabs,
            'medical_device_notice' => $medicalDeviceNotice,
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
            'source' => 'reh4mat',
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
            'description' => '',
            'description_html' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'stock_quantity' => null,
            'sku' => null,
            'ean' => null,
            'product_meta' => [],
            'codes' => [],
            'images' => [],
            'pictograms' => [],
            'regulatory_icons' => [],
            'downloads' => [],
            'tabs' => [],
            'medical_device_notice' => null,
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
            '//*[@id="opis-produktu"]//h1[contains(concat(" ", normalize-space(@class), " "), " product-title ")][1]',
            '//h1[contains(concat(" ", normalize-space(@class), " "), " product-title ")][1]',
            '//h2[contains(concat(" ", normalize-space(@class), " "), " itemTitle ")][1]',
            '//*[@id="content"]//h1[1]',
            '//main//h1[1]',
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

    private function extractExternalProductId(DOMXPath $xpath, string $html): ?string
    {
        $shortlink = $this->firstElement($xpath, '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "shortlink"][@href]');

        if ($shortlink instanceof DOMElement && preg_match('/[?&]p=([0-9]+)/u', $shortlink->getAttribute('href'), $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\bpostid-([0-9]+)\b/u', $html, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\bid=["\']child_page-([0-9]+)["\']/u', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractProductMeta(DOMXPath $xpath): array
    {
        $meta = [];
        $rows = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-meta ")]//tr[td]');

        if ($rows === false) {
            return [];
        }

        foreach ($rows as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = $xpath->query('./td', $row);

            if ($cells === false || $cells->length < 2) {
                continue;
            }

            $keyCell = $cells->item(0);
            $valueCell = $cells->item(1);

            if (! $keyCell instanceof DOMElement || ! $valueCell instanceof DOMElement) {
                continue;
            }

            $key = $this->normalizeLabel($keyCell->textContent ?? '');
            $value = $this->normalizeLabel($valueCell->textContent ?? '');

            if ($key !== '' && $value !== '') {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    private function skuFromProductMeta(array $productMeta): ?string
    {
        foreach (['Kod katalogowy', 'Model', 'Symbol produktu'] as $key) {
            if (isset($productMeta[$key]) && $this->normalizeLabel((string) $productMeta[$key]) !== '') {
                return $this->normalizeLabel((string) $productMeta[$key]);
            }
        }

        return null;
    }

    private function skuFromName(string $name): ?string
    {
        $name = $this->normalizeLabel($name);

        if ($name === '') {
            return null;
        }

        if (preg_match('/\b[A-Z0-9]{1,8}(?:-[A-Z0-9]{1,8})+(?:\/[A-Z0-9]+)?\b/u', $name, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function extractBrand(DOMXPath $xpath, string $html): ?string
    {
        foreach ([
            '//*[@id="kody"]//a[contains(@href, "/marka/")][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " header-watermark ")][@alt][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            $brand = $node->hasAttribute('title') ? $node->getAttribute('title') : ($node->hasAttribute('alt') ? $node->getAttribute('alt') : $node->textContent);
            $brand = $this->cleanBrandCandidate($brand ?? null);

            if ($brand !== null) {
                return $brand;
            }
        }

        if (preg_match('/Marka:\s*([^\n\r<]{2,80})/iu', $this->htmlToText($html), $matches) === 1) {
            return $this->cleanBrandCandidate($matches[1]);
        }

        return null;
    }

    private function cleanBrandCandidate(?string $brand): ?string
    {
        if ($brand === null) {
            return null;
        }

        $brand = $this->normalizeLabel($brand);
        $brand = preg_replace('/^(?:Marka|Brand)\s*:\s*/iu', '', $brand) ?? $brand;
        $brand = preg_replace('/\s+(?:Kod|NFZ|UMDNS|UNSPSC|Opis|Do pobrania)\b.*$/iu', '', $brand) ?? $brand;
        $brand = $this->normalizeLabel($brand);

        if ($brand === '' || mb_strlen($brand) > 80) {
            return null;
        }

        return $brand;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCodes(DOMXPath $xpath, string $html): array
    {
        $codes = [];

        $kody = $this->firstElement($xpath, '//*[@id="kody"][1]');

        if ($kody instanceof DOMElement) {
            $text = $this->normalizeLabel($kody->textContent ?? '');

            foreach (['UNSPSC', 'UMDNS', 'NFZ'] as $codeName) {
                if (preg_match('/Kod\s+'.$codeName.'\s*:?\s*([^\n\r]+?)(?=\s+Kod\s+(?:UNSPSC|UMDNS|NFZ)|$)/iu', $text, $matches) === 1) {
                    $values = $this->codeValuesFromText($matches[1]);

                    if ($values !== []) {
                        $codes[$codeName] = $values;
                    }
                }
            }
        }

        $allText = $this->normalizeLabel($this->htmlToText($html));

        foreach (['UNSPSC', 'UMDNS', 'NFZ'] as $codeName) {
            if (isset($codes[$codeName])) {
                continue;
            }

            if (preg_match('/Kod\s+'.$codeName.'\s*:?\s*([^\n\r]+?)(?=\s+Kod\s+(?:UNSPSC|UMDNS|NFZ)|\s+[A-ZĄĆĘŁŃÓŚŹŻ][a-ząćęłńóśźż]+:|$)/iu', $allText, $matches) === 1) {
                $values = $this->codeValuesFromText($matches[1]);

                if ($values !== []) {
                    $codes[$codeName] = $values;
                }
            }
        }

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    private function codeValuesFromText(string $value): array
    {
        $value = $this->normalizeLabel($value);
        $value = preg_replace('/\s+(?:Opis|Tabela|Do pobrania|Sposób zakładania|PRODUCENT)\b.*$/iu', '', $value) ?? $value;

        preg_match_all('/[A-Z]{1,4}\.[0-9]{2}\.[0-9]{2}\.[0-9]{2}|[0-9]{4,10}/u', $value, $matches);

        if (($matches[0] ?? []) === []) {
            return [];
        }

        return array_values(array_unique(array_map(fn (string $code): string => $this->normalizeLabel($code), $matches[0])));
    }

    /**
     * @return array<int, string>
     */
    private function extractBreadcrumbCategories(DOMXPath $xpath, string $productName): array
    {
        $nodes = $xpath->query(
            '//*[@id="crumbs"]//a'
            .' | //ol[contains(concat(" ", normalize-space(@class), " "), " breadcrumb ")]//a'
            .' | //nav[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]//a'
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

    /**
     * @return array<int, array{title: string, id: string|null, html: string, text: string}>
     */
    private function extractTabs(DOMXPath $xpath, string $baseUrl): array
    {
        $tabs = [];

        $tabTitles = $xpath->query('//h2[contains(concat(" ", normalize-space(@class), " "), " tabtitle ")]');

        if ($tabTitles !== false) {
            foreach ($tabTitles as $titleNode) {
                if (! $titleNode instanceof DOMElement) {
                    continue;
                }

                $title = $this->normalizeLabel($titleNode->textContent ?? '');
                $content = $this->nextElementSibling($titleNode);

                if (! $content instanceof DOMElement || ! $this->hasClass($content, 'tabcontent')) {
                    continue;
                }

                $html = $this->cleanHtml($this->innerHtml($content));

                if ($title !== '' && $this->hasMeaningfulTabHtml($html)) {
                    $tabs[$this->normalizeComparableName($title)] = [
                        'title' => $title,
                        'id' => null,
                        'html' => $this->normalizeEmbeddedAssetUrlsInHtml($html, $baseUrl),
                        'text' => $this->htmlToText($html),
                    ];
                }
            }
        }

        $tabPanes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " tab-pane ")]');

        if ($tabPanes !== false) {
            foreach ($tabPanes as $pane) {
                if (! $pane instanceof DOMElement) {
                    continue;
                }

                $id = $pane->hasAttribute('id') ? $pane->getAttribute('id') : null;
                $title = $this->tabTitleForPane($xpath, $id) ?? $this->titleFromId($id);
                $html = $this->cleanHtml($this->innerHtml($pane));

                if ($title !== '' && $this->hasMeaningfulTabHtml($html)) {
                    $tabs[$this->normalizeComparableName($title)] = [
                        'title' => $title,
                        'id' => $id,
                        'html' => $this->normalizeEmbeddedAssetUrlsInHtml($html, $baseUrl),
                        'text' => $this->htmlToText($html),
                    ];
                }
            }
        }

        return array_values($tabs);
    }

    private function hasMeaningfulTabHtml(string $html): bool
    {
        if ($this->htmlToText($html) !== '') {
            return true;
        }

        return preg_match('/<(a|img|embed|iframe|object)\b/i', $html) === 1;
    }

    private function tabTitleForPane(DOMXPath $xpath, ?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $nodes = $xpath->query('//a[@href="#'.$id.'" or @aria-controls="'.$id.'"][1]');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $title = $this->normalizeLabel($node->textContent ?? '');

        return $title === '' ? null : $title;
    }

    private function titleFromId(?string $id): string
    {
        if ($id === null || $id === '') {
            return '';
        }

        return mb_convert_case(str_replace('_', ' ', $id), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param  array<int, array{title: string, id: string|null, html: string, text: string}>  $tabs
     */
    private function extractDescriptionHtml(DOMXPath $xpath, array $tabs): ?string
    {
        foreach ($tabs as $tab) {
            $key = $this->normalizeComparableName($tab['title']);

            if (in_array($key, ['opis', 'description'], true) || str_contains($key, 'opis produktu')) {
                return $tab['html'];
            }
        }

        foreach ([
            '//*[contains(concat(" ", normalize-space(@class), " "), " itemFullText ")][1]',
            '//*[@id="opis-produktu"][1]',
            '//*[@id="content"][1]',
            '//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " column ")][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            $html = $this->cleanHtml($this->innerHtml($node));

            if ($this->htmlToText($html) !== '') {
                return $html;
            }
        }

        return null;
    }

    private function extractShortDescription(?string $seoDescription, ?string $descriptionHtml): ?string
    {
        $short = $this->normalizeLabel((string) $seoDescription);

        if ($short !== '') {
            return mb_substr($short, 0, 500);
        }

        $description = $this->htmlToText($descriptionHtml);

        if ($description === '') {
            return null;
        }

        return mb_substr($description, 0, 500);
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

        foreach ([
            '//*[@id="opis-produktu"]//*[contains(concat(" ", normalize-space(@class), " "), " header-picture ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " itemImageBlock ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " itemImageBlock ")]//img',
            '//*[@id="opis-produktu"]//a[@href][img]',
            '//*[@id="opis-produktu"]//img',
            '//*[contains(concat(" ", normalize-space(@class), " "), " itemFullText ")]//img',
            '//*[@id="content"]/p[1]//a[@href][img]',
            '//*[@id="content"]/p[1]//img',
        ] as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                if ($this->isDecorativeImageContext($xpath, $node)) {
                    continue;
                }

                if ($node->tagName === 'a' && $node->hasAttribute('href')) {
                    $image = $this->firstElementFromContext($xpath, './/img[1]', $node);

                    if ($image instanceof DOMElement && $this->isDecorativeImageContext($xpath, $image)) {
                        continue;
                    }

                    $alt = $image instanceof DOMElement ? $this->normalizeLabel($image->getAttribute('alt')) : $productName;
                    $this->addImage($images, $node->getAttribute('href'), $baseUrl, $alt ?: $productName);

                    continue;
                }

                $alt = $this->normalizeLabel($node->getAttribute('alt')) ?: $productName;

                foreach (['data-src', 'data-original', 'src'] as $attribute) {
                    if ($node->hasAttribute($attribute)) {
                        $this->addImage($images, $node->getAttribute($attribute), $baseUrl, $alt);
                    }
                }

                if ($node->hasAttribute('srcset')) {
                    foreach ($this->srcsetUrls($node->getAttribute('srcset')) as $srcsetUrl) {
                        $this->addImage($images, $srcsetUrl, $baseUrl, $alt);
                    }
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

    private function isDecorativeImageContext(DOMXPath $xpath, DOMElement $node): bool
    {
        $query = implode(' | ', [
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " piktogramy-container ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " piktogramy-prawa ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " piktogram ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " zalety-wrapper ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " zaleta-wrapper ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " header-watermark-container ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " header-watermark ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " ce ")]',
            './ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " etykieta ")]',
        ]);

        $nodes = $xpath->query($query, $node);

        return $nodes !== false && $nodes->length > 0;
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

        foreach (['logo', 'icon', 'ikony', 'flags', 'favicon', 'apple-icon', 'ce.png', 'md.png', 'CLASS-I-MD', 'class-i-md', 'watermark'] as $blocked) {
            if (str_contains($pathLower, mb_strtolower($blocked))) {
                return false;
            }
        }

        if (str_contains($pathLower, '/wp-content/themes/')) {
            return false;
        }

        $altNormalized = $this->normalizeComparableName((string) $alt);

        return str_contains($pathLower, '/uploads/')
            || str_contains($pathLower, '/media/k2/items/cache/')
            || str_contains($pathLower, '/images/produkty/')
            || str_contains($altNormalized, 'orteza')
            || str_contains($altNormalized, 'stabiliz')
            || str_contains($altNormalized, 'separator')
            || str_contains($altNormalized, 'podusz');
    }

    private function canonicalImageKey(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $pathLower = mb_strtolower($path);
        $pathLower = preg_replace('/-(?:[0-9]{2,4})x(?:[0-9]{2,4})(?=\.(?:jpe?g|png|webp)$)/i', '', $pathLower) ?? $pathLower;
        $pathLower = preg_replace('/_(?:xs|s|m|l|xl|generic)(?=\.(?:jpe?g|png|webp)$)/i', '', $pathLower) ?? $pathLower;

        return $pathLower;
    }

    private function imageQualityScore(string $url): int
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $pathLower = mb_strtolower($path);

        if (preg_match('/[-_](\d{2,4})x(\d{2,4})(?=\.(?:jpe?g|png|webp)$)/i', $pathLower, $matches) === 1) {
            return max((int) $matches[1], (int) $matches[2]);
        }

        foreach (['_xl.', '_l.', '_m.', '_s.', '_xs.'] as $index => $marker) {
            if (str_contains($pathLower, $marker)) {
                return [1000, 800, 600, 300, 150][$index];
            }
        }

        if (preg_match('/[-_][0-9]{2,4}x[0-9]{2,4}(?=\.(?:jpe?g|png|webp)$)/i', $pathLower) !== 1) {
            return 1200;
        }

        return 700;
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
     * @return array<int, array{label: string, image_url: string|null, description: string|null, source: string}>
     */
    private function extractPictograms(DOMXPath $xpath, string $baseUrl): array
    {
        $pictograms = [];

        $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " piktogram ")]');

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement || $this->hasClass($node, 'piktogramy-container') || $this->hasClass($node, 'piktogramy-prawa')) {
                    continue;
                }

                $image = $this->firstElementFromContext($xpath, './/img[@src][1]', $node);
                $span = $this->firstElementFromContext($xpath, './/span[1]', $node);
                $label = $span instanceof DOMElement ? $this->normalizeLabel($span->textContent ?? '') : '';
                $imageUrl = $image instanceof DOMElement ? $this->normalizeAssetUrl($image->getAttribute('src'), $baseUrl) : null;
                $alt = $image instanceof DOMElement ? $this->normalizeLabel($image->getAttribute('alt')) : '';

                if ($label === '') {
                    $label = $alt;
                }

                $this->addPictogram($pictograms, $label, $imageUrl, null, 'reh4mat_piktogram');
            }
        }

        $zalety = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " zaleta-wrapper ")]');

        if ($zalety !== false) {
            foreach ($zalety as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $image = $this->firstElementFromContext($xpath, './/img[@src][1]', $node);
                $imageUrl = $image instanceof DOMElement ? $this->normalizeAssetUrl($image->getAttribute('src'), $baseUrl) : null;
                $alt = $image instanceof DOMElement ? $this->normalizeLabel($image->getAttribute('alt')) : '';
                $description = $this->normalizeLabel($node->getAttribute('title'));

                if ($description === '') {
                    $next = $this->nextElementSibling($node);
                    $description = $next instanceof DOMElement && $this->hasClass($next, 'hidden')
                        ? $this->normalizeLabel($next->textContent ?? '')
                        : '';
                }

                $label = $alt !== '' ? $alt : $this->labelFromAssetUrl($imageUrl);

                if ($label === '' && $description !== '') {
                    $label = mb_substr($description, 0, 80);
                }

                $this->addPictogram($pictograms, $label, $imageUrl, $description === '' ? null : $description, 'stabilobed_zaleta');
            }
        }

        return array_values($pictograms);
    }

    /**
     * @param  array<string, array{label: string, image_url: string|null, description: string|null, source: string}>  $pictograms
     */
    private function addPictogram(array &$pictograms, string $label, ?string $imageUrl, ?string $description, string $source): void
    {
        $label = $this->normalizeLabel($label);
        $description = $description !== null && $this->normalizeLabel($description) !== '' ? $this->normalizeLabel($description) : null;

        if ($label === '' && $imageUrl === null) {
            return;
        }

        $key = mb_strtolower(($imageUrl ?? '').'|'.$label);

        if (isset($pictograms[$key])) {
            return;
        }

        $pictograms[$key] = [
            'label' => $label,
            'image_url' => $imageUrl,
            'description' => $description,
            'source' => $source,
        ];
    }

    private function labelFromAssetUrl(?string $url): string
    {
        if ($url === null) {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $base = str_replace(['-', '_'], ' ', $base);

        return $this->normalizeLabel($base);
    }

    /**
     * @return array<int, array{label: string, image_url: string, description: string|null}>
     */
    private function extractRegulatoryIcons(DOMXPath $xpath, string $baseUrl): array
    {
        $icons = [];
        $nodes = $xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " ce ")]//img[@src]');

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $imageUrl = $this->normalizeAssetUrl($node->getAttribute('src'), $baseUrl);

            if ($imageUrl === null) {
                continue;
            }

            $label = $this->normalizeLabel($node->getAttribute('alt')) ?: mb_strtoupper(pathinfo((string) parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME));
            $description = $this->normalizeLabel($node->parentNode?->textContent ?? '');

            $icons[mb_strtolower($imageUrl)] = [
                'label' => $label,
                'image_url' => $imageUrl,
                'description' => $description === '' ? null : $description,
            ];
        }

        return array_values($icons);
    }

    /**
     * @return array<int, array{label: string, url: string, type: string|null}>
     */
    private function extractDownloads(DOMXPath $xpath, string $baseUrl): array
    {
        $downloads = [];

        foreach ([
            '//ul[contains(concat(" ", normalize-space(@class), " "), " pdf ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " itemAttachments ")]//a[@href]',
            '//*[@id="do_pobrania"]//a[@href]',
            '//embed[@src]',
        ] as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $href = $node->tagName === 'embed' ? $node->getAttribute('src') : $node->getAttribute('href');
                $downloadUrl = $this->normalizeAssetUrl($href, $baseUrl);

                if ($downloadUrl === null || ! $this->looksLikeDownload($downloadUrl)) {
                    continue;
                }

                $label = $this->normalizeLabel($node->textContent ?? '') ?: $this->normalizeLabel($node->getAttribute('title'));

                if ($label === '') {
                    $label = pathinfo((string) parse_url($downloadUrl, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'download';
                }

                $downloads[mb_strtolower($downloadUrl)] = [
                    'label' => $label,
                    'url' => $downloadUrl,
                    'type' => $this->downloadTypeFromUrl($downloadUrl),
                ];
            }
        }

        return array_values($downloads);
    }

    private function looksLikeDownload(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(pdf|docx?|xlsx?|zip)$/iu', $path) === 1
            || str_contains($path, '/download/');
    }

    private function downloadTypeFromUrl(string $url): ?string
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('/\.([a-z0-9]{2,5})$/iu', $path, $matches) === 1) {
            return $matches[1];
        }

        if (str_contains($path, '/download/')) {
            return 'download';
        }

        return null;
    }

    private function extractMedicalDeviceNotice(DOMXPath $xpath, string $html): ?string
    {
        foreach ([
            '//*[contains(concat(" ", normalize-space(@class), " "), " etykieta ")][1]',
            '//p[contains(concat(" ", normalize-space(@class), " "), " ce ")][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZĄĆĘŁŃÓŚŹŻ", "abcdefghijklmnopqrstuvwxyząćęłńóśźż"), "wyrób medyczny")][1]',
        ] as $query) {
            $node = $this->firstElement($xpath, $query);

            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizeLabel($node->textContent ?? '');

            if ($text !== '') {
                return $this->normalizeSentenceSpacing($text);
            }
        }

        if (preg_match('/To\s+jest\s+wyr[oó]b\s+medyczny\.?\s*U[żz]ywaj\s+go\s+zgodnie\s+z\s+instrukcj[ąa]\s+u[żz]ywania\s+lub\s+etykiet[ąa]\.?/iu', $this->htmlToText($html), $matches) === 1) {
            return $this->normalizeSentenceSpacing($this->normalizeLabel($matches[0]));
        }

        return null;
    }

    private function containsMedicalDeviceNotice(string $html): bool
    {
        $text = $this->normalizeComparableName($this->htmlToText($html));

        return preg_match('/\bwyrob[a-z]*\s+medyczn[a-z]*\b/u', $text) === 1
            || str_contains($text, 'medical device');
    }

    private function normalizeEmbeddedAssetUrlsInHtml(string $html, string $baseUrl): string
    {
        return preg_replace_callback(
            '/\s(src|href)=("|\')([^"\']+)(\2)/iu',
            function (array $matches) use ($baseUrl): string {
                $url = $this->normalizeAssetUrl($matches[3], $baseUrl) ?? $this->absoluteUrl($matches[3], $baseUrl) ?? $matches[3];

                return ' '.$matches[1].'='.$matches[2].htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8').$matches[4];
            },
            $html,
        ) ?? $html;
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

    private function nextElementSibling(DOMElement $element): ?DOMElement
    {
        $node = $element->nextSibling;

        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement) {
                return $node;
            }

            $node = $node->nextSibling;
        }

        return null;
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
        $html = preg_replace('/<!--.*?-->/su', '', $html) ?? $html;
        $html = preg_replace('/\s+(data-[a-z0-9_-]+|wire:[a-z0-9_.-]+|onclick)="[^"]*"/iu', '', $html) ?? $html;

        return trim($html);
    }

    private function htmlToText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return $this->normalizeLabel(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->absoluteUrl($url, $baseUrl ?? 'https://'.self::REH4MAT_HOST.'/');

        if ($absolute === null) {
            return null;
        }

        $host = parse_url($absolute, PHP_URL_HOST);

        if ($host === null || mb_strtolower($host) !== self::REH4MAT_HOST) {
            return null;
        }

        $path = (string) parse_url($absolute, PHP_URL_PATH);

        if (! str_starts_with($path, '/produkt/')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if (count($segments) < 3) {
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

        if ($host === null || ! in_array(mb_strtolower($host), self::ALLOWED_ASSET_HOSTS, true)) {
            return null;
        }

        return $this->withoutQueryAndFragment($absolute);
    }

    private function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
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
            $host = parse_url($baseUrl, PHP_URL_HOST) ?: self::REH4MAT_HOST;

            return $scheme.'://'.$host.$url;
        }

        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
        $baseDirectory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        if ($baseDirectory === '' || $baseDirectory === '.') {
            $baseDirectory = '';
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: self::REH4MAT_HOST;

        return $scheme.'://'.$host.$baseDirectory.'/'.$url;
    }

    private function withoutQueryAndFragment(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST) ?: self::REH4MAT_HOST;
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

        return in_array($normalized, [
            'home',
            'start',
            'strona glowna',
            'reh4mat',
            'produkty',
        ], true);
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return str_contains(' '.$element->getAttribute('class').' ', ' '.$class.' ');
    }

    private function normalizeLabel(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeSentenceSpacing(string $value): string
    {
        $value = preg_replace('/([.!?])(?=\p{L})/u', '$1 ', $value) ?? $value;

        return $this->normalizeLabel($value);
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

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->requestDelayMilliseconds * 1000);
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopProductScraper/1.0; +https://konjishop.pl)',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
