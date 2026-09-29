<?php

declare(strict_types=1);

namespace App\Services\Iconic;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class IconicProductScraper
{
    private const HOST = 'sklep.iconic.pl';

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
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $context = null): array
    {
        $normalizedUrl = $this->normalizeProductUrl($url);

        if ($normalizedUrl === null) {
            return $this->failedResult($url, 'invalid_iconic_product_url', $context);
        }

        $this->emit('Fetching Iconic product page: '.$normalizedUrl);
        $this->pauseBeforeRequest();

        try {
            $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->withHeaders($this->headers())
                ->get($normalizedUrl);
        } catch (Throwable $exception) {
            return $this->failedResult($normalizedUrl, $exception->getMessage(), $context);
        }

        if (! $response->successful()) {
            return $this->failedResult($normalizedUrl, 'HTTP '.$response->status(), $context);
        }

        return $this->extract($response->body(), $normalizedUrl, $context);
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public function extract(string $html, string $sourceUrl, ?array $context = null): array
    {
        $sourceUrl = $this->normalizeProductUrl($sourceUrl) ?? $sourceUrl;

        try {
            $crawler = new Crawler($html, $sourceUrl);
        } catch (Throwable) {
            return $this->failedResult($sourceUrl, 'invalid_product_html', $context);
        }

        $canonicalUrl = $this->canonicalUrl($crawler, $sourceUrl);
        $identityUrl = $canonicalUrl ?? $sourceUrl;
        $name = $this->productName($crawler);
        $summaryText = $this->productSummaryText($crawler, $name);
        $descriptionHtml = $this->descriptionHtml($crawler, $sourceUrl, $name);
        $descriptionPlain = $this->normalizeText(strip_tags((string) $descriptionHtml));
        $categoryPath = $this->categoryPath($crawler);
        $price = $this->priceGrossAmount($summaryText);
        $onOrder = $this->containsComparable($summaryText, 'produkt na zamowienie');
        $availabilityLabel = $this->labelValue($summaryText, 'Dostępny')
            ?? ($onOrder ? 'Produkt na zamówienie' : null);
        $catalogueNumber = $this->labelValue($summaryText, 'Numer katalogowy');
        $unit = $this->labelValue($summaryText, 'Jednostka miary');
        $shippingTime = $this->labelValue($summaryText, 'Czas wysyłki');
        $variantCandidates = $this->variantCandidates($crawler, $price);
        $medicalEvidence = $this->medicalDeviceEvidence($descriptionPlain.' '.$summaryText);
        $warnings = [];

        if ($name === '') {
            $warnings[] = 'Product name not found.';
        }

        if ($price === null) {
            $warnings[] = $onOrder
                ? 'Product is marked as on-order and has no authoritative retail price on the product page.'
                : 'Gross price not found on the product page.';
        }

        if ($catalogueNumber === null) {
            $warnings[] = 'Catalogue number not found.';
        }

        if ($descriptionPlain === '') {
            $warnings[] = 'Product description not found.';
        }

        $images = $this->images($crawler, $sourceUrl, $html);

        if ($images === []) {
            $warnings[] = 'Product gallery images not found.';
        }

        $seoTitle = $this->metaContent($crawler, 'meta[property="og:title"], meta[name="twitter:title"]')
            ?? $this->text($crawler, 'title')
            ?? ($name !== '' ? $name : null);
        $seoDescription = $this->metaContent(
            $crawler,
            'meta[name="description"], meta[property="og:description"], meta[name="twitter:description"]',
        );

        return [
            'source' => 'iconic',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $this->externalProductIdFromUrl($identityUrl),
            'slug' => $this->slugFromUrl($identityUrl),
            'name' => $name,
            'brand' => [
                'name' => 'ICONIC',
                'slug' => 'iconic',
            ],
            'sku' => $catalogueNumber,
            'catalogue_number' => $catalogueNumber,
            'ean' => null,
            'price_gross_amount' => $price,
            'currency' => 'PLN',
            'availability' => $this->availability($availabilityLabel, $onOrder),
            'availability_label' => $availabilityLabel,
            'is_on_order' => $onOrder,
            'shipping_time' => $shippingTime,
            'unit' => $unit,
            'category' => $categoryPath !== [] ? end($categoryPath) : null,
            'categories' => $categoryPath,
            'source_category_path' => $categoryPath,
            'description_html' => $descriptionHtml,
            'description_plain' => $descriptionPlain !== '' ? $descriptionPlain : null,
            'short_description' => $this->shortDescription($seoDescription, $descriptionPlain),
            'seo_title' => $seoTitle !== null ? $this->normalizeText($seoTitle) : null,
            'seo_description' => $seoDescription !== null ? $this->normalizeText($seoDescription) : null,
            'images' => $images,
            'attributes' => array_values(array_filter([
                $unit !== null ? $this->attribute('Jednostka miary', $unit) : null,
                $catalogueNumber !== null ? $this->attribute('Numer katalogowy', $catalogueNumber) : null,
            ])),
            'variant_candidates' => $variantCandidates,
            'downloads' => $this->downloads($crawler, $sourceUrl),
            'is_medical_device' => $medicalEvidence['is_medical_device'],
            'medical_device_class' => $medicalEvidence['class'],
            'medical_device_evidence' => $medicalEvidence['evidence'],
            'raw_context' => $context,
            'warnings' => $warnings,
            'failed_urls' => [],
        ];
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        if (! str_starts_with($path, '/produkty/')
            || ! str_ends_with(mb_strtolower($path), '.html')) {
            return null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function failedResult(string $url, string $reason, ?array $context): array
    {
        return [
            'source' => 'iconic',
            'source_url' => $url,
            'canonical_url' => null,
            'external_product_id' => null,
            'slug' => null,
            'name' => '',
            'brand' => ['name' => 'ICONIC', 'slug' => 'iconic'],
            'sku' => null,
            'catalogue_number' => null,
            'ean' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'is_on_order' => false,
            'shipping_time' => null,
            'unit' => null,
            'category' => null,
            'categories' => [],
            'source_category_path' => [],
            'description_html' => null,
            'description_plain' => null,
            'short_description' => null,
            'seo_title' => null,
            'seo_description' => null,
            'images' => [],
            'attributes' => [],
            'variant_candidates' => [],
            'downloads' => [],
            'is_medical_device' => false,
            'medical_device_class' => null,
            'medical_device_evidence' => null,
            'raw_context' => $context,
            'warnings' => [],
            'failed_urls' => [$url => $reason],
        ];
    }

    private function canonicalUrl(Crawler $crawler, string $sourceUrl): ?string
    {
        foreach ([
            ['link[rel="canonical"][href]', 'href'],
            ['meta[property="og:url"][content]', 'content'],
        ] as [$selector, $attribute]) {
            $value = $this->attr($crawler, $selector, $attribute);

            if ($value === null) {
                continue;
            }

            $url = $this->normalizeProductUrl($value, $sourceUrl);

            if ($url !== null) {
                return $url;
            }
        }

        return $this->normalizeProductUrl($sourceUrl);
    }

    private function productName(Crawler $crawler): string
    {
        foreach ([
            '.product-name h2',
            '.productName h2',
            'h2',
            '.product-name h1',
            '.productName h1',
            'h1',
            '[itemprop="name"]',
        ] as $selector) {
            $value = $this->text($crawler, $selector);

            if ($value === null) {
                continue;
            }

            $value = $this->normalizeText($value);

            if ($value !== ''
                && ! in_array($this->comparable($value), ['opis produktu', 'inne produkty w kategorii'], true)) {
                return $value;
            }
        }

        return '';
    }

    private function productSummaryText(Crawler $crawler, string $name): string
    {
        $body = $this->text($crawler, 'body') ?? '';

        if ($body === '') {
            return '';
        }

        $start = $name !== '' ? mb_strpos($body, $name) : false;

        if ($start !== false) {
            $body = mb_substr($body, $start + mb_strlen($name));
        }

        $end = mb_stripos($body, 'Opis produktu');

        if ($end !== false) {
            $body = mb_substr($body, 0, $end);
        }

        return $this->normalizeText($body);
    }

    private function priceGrossAmount(string $summaryText): ?float
    {
        if ($summaryText === '') {
            return null;
        }

        if (preg_match('/\b(\d{1,7}(?:[\s. ]\d{3})*[,.]\d{2})\s*zł\b/iu', $summaryText, $matches) !== 1) {
            return null;
        }

        $amount = str_replace(["\xc2\xa0", ' '], '', $matches[1]);
        $commaPosition = strrpos($amount, ',');
        $dotPosition = strrpos($amount, '.');

        if ($commaPosition !== false && $dotPosition !== false) {
            $decimalSeparator = $commaPosition > $dotPosition ? ',' : '.';
            $groupingSeparator = $decimalSeparator === ',' ? '.' : ',';
            $amount = str_replace($groupingSeparator, '', $amount);
            $amount = str_replace($decimalSeparator, '.', $amount);
        } elseif ($commaPosition !== false) {
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        } elseif ($dotPosition !== false) {
            $amount = str_replace(',', '', $amount);
        }

        return is_numeric($amount) ? (float) $amount : null;
    }

    private function labelValue(string $summaryText, string $label): ?string
    {
        if ($summaryText === '') {
            return null;
        }

        $knownLabels = [
            'Dostępny',
            'Czas wysyłki',
            'Jednostka miary',
            'Numer katalogowy',
            'Rozmiar',
            'Kolor',
            'Strona',
            'Wariant',
        ];

        $stoppers = array_values(array_filter(
            $knownLabels,
            fn (string $candidate): bool => $this->comparable($candidate) !== $this->comparable($label),
        ));

        $stopPattern = implode('|', array_map(
            fn (string $candidate): string => preg_quote($candidate, '/'),
            $stoppers,
        ));

        $pattern = '/'.preg_quote($label, '/').'\s*:\s*(.+?)(?=\s+(?:'.$stopPattern.')\s*:|\s+DODAJ\b|\s+ZAMÓW\b|$)/iu';

        if (preg_match($pattern, $summaryText, $matches) !== 1) {
            return null;
        }

        $value = $this->normalizeText($matches[1]);

        if ($value === '') {
            return null;
        }

        $comparableValue = $this->comparable($value);

        if ($comparableValue === ''
            || str_starts_with($comparableValue, 'dodaj do koszyka')
            || str_starts_with($comparableValue, 'dodaj do listy zyczen')
            || str_starts_with($comparableValue, 'zamow produkt')) {
            return null;
        }

        return $value;
    }

    private function availability(?string $label, bool $onOrder): string
    {
        if ($onOrder) {
            return 'on_order';
        }

        $value = $this->comparable((string) $label);

        if ($value === '') {
            return 'unknown';
        }

        if (str_contains($value, 'niedostep')
            || str_contains($value, 'brak')
            || str_contains($value, 'wyczerp')) {
            return 'out_of_stock';
        }

        if (str_contains($value, 'dostep')
            || str_contains($value, 'ogranicz')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    /**
     * @return list<string>
     */
    private function categoryPath(Crawler $crawler): array
    {
        foreach ([
            '.breadcrumbs',
            '.breadcrumb',
            '#breadcrumbs',
            '#breadcrumb',
            '[aria-label="breadcrumb"]',
            'nav.breadcrumbs',
            'nav.breadcrumb',
            'ol.breadcrumb',
            'ul.breadcrumb',
            '.path',
            '.product-path',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
            } catch (Throwable) {
                continue;
            }

            if ($node->count() === 0) {
                continue;
            }

            $categories = [];

            try {
                $node->filter('a')->each(function (Crawler $link) use (&$categories): void {
                    $label = $this->normalizeText($link->text('', false));

                    if ($label === '' || in_array($this->comparable($label), ['home', 'strona glowna'], true)) {
                        return;
                    }

                    $href = (string) $link->attr('href');

                    if ($href === '' || ! str_contains($href, '/produkty/')) {
                        return;
                    }

                    if (str_ends_with(mb_strtolower((string) parse_url($href, PHP_URL_PATH)), '.html')) {
                        return;
                    }

                    $categories[] = $label;
                });
            } catch (Throwable) {
                continue;
            }

            $categories = array_values(array_unique($categories));

            if ($categories !== []) {
                return $categories;
            }
        }

        return [];
    }

    private function descriptionHtml(Crawler $crawler, string $sourceUrl, string $productName): ?string
    {
        foreach ([
            '#opis-produktu',
            '#opis',
            '#description',
            '#productDescription',
            '.product-description',
            '.productDescription',
            '.product-description-content',
            '.productDescriptionContent',
            '[data-tab="description"]',
            '[data-tab-content="description"]',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
            } catch (Throwable) {
                continue;
            }

            if ($node->count() === 0) {
                continue;
            }

            $html = $this->sanitizeHtml($node->html(''), $sourceUrl);

            if ($this->normalizeText(strip_tags($html)) !== '') {
                return $html;
            }
        }

        return $this->descriptionHtmlFromHeading($crawler, $sourceUrl)
            ?? $this->descriptionHtmlFromContentHeading($crawler, $sourceUrl, $productName);
    }

    private function descriptionHtmlFromHeading(Crawler $crawler, string $sourceUrl): ?string
    {
        try {
            $headings = $crawler->filter('h1, h2, h3, h4, h5, h6');
        } catch (Throwable) {
            return null;
        }

        $headingNode = null;

        $headings->each(function (Crawler $heading) use (&$headingNode): void {
            if ($headingNode !== null) {
                return;
            }

            $text = $this->comparable($heading->text('', false));

            if ($text === 'opis produktu') {
                $headingNode = $heading->getNode(0);
            }
        });

        if (! $headingNode instanceof DOMNode) {
            return null;
        }

        $html = '';
        $current = $headingNode->nextSibling;
        $captured = 0;

        while ($current instanceof DOMNode && $captured < 200) {
            if ($current instanceof DOMElement
                && preg_match('/^h[1-6]$/i', $current->tagName) === 1) {
                $heading = $this->comparable($current->textContent ?? '');

                if (in_array($heading, [
                    'do pobrania',
                    'instrukcja uzytkowania',
                    'inne produkty w kategorii',
                    'powiazane produkty',
                ], true)) {
                    break;
                }
            }

            if ($current instanceof DOMElement) {
                $document = $current->ownerDocument;
                $fragment = $document?->saveHTML($current);

                if (is_string($fragment)) {
                    $html .= $fragment;
                }
            } elseif (trim((string) $current->textContent) !== '') {
                $html .= '<p>'.e($this->normalizeText((string) $current->textContent)).'</p>';
            }

            $current = $current->nextSibling;
            $captured++;
        }

        $html = $this->sanitizeHtml($html, $sourceUrl);

        return $this->normalizeText(strip_tags($html)) !== '' ? $html : null;
    }

    private function descriptionHtmlFromContentHeading(
        Crawler $crawler,
        string $sourceUrl,
        string $productName,
    ): ?string {
        try {
            $headings = $crawler->filter('h1, h2, h3, h4');
        } catch (Throwable) {
            return null;
        }

        $productComparable = $this->comparable($productName);
        $contentHeading = null;

        $headings->each(function (Crawler $heading) use (&$contentHeading, $productComparable): void {
            if ($contentHeading !== null) {
                return;
            }

            $text = $this->normalizeText($heading->text('', false));
            $comparable = $this->comparable($text);

            if ($text === ''
                || $comparable === $productComparable
                || in_array($comparable, [
                    'opis produktu',
                    'do pobrania',
                    'instrukcja uzytkowania',
                    'inne produkty w kategorii',
                    'powiazane produkty',
                ], true)) {
                return;
            }

            $contentHeading = $heading->getNode(0);
        });

        if (! $contentHeading instanceof DOMNode) {
            return null;
        }

        $html = '';
        $current = $contentHeading;
        $captured = 0;

        while ($current instanceof DOMNode && $captured < 250) {
            if ($current !== $contentHeading
                && $current instanceof DOMElement
                && preg_match('/^h[1-6]$/i', $current->tagName) === 1) {
                $heading = $this->comparable($current->textContent ?? '');

                if (in_array($heading, [
                    'do pobrania',
                    'instrukcja uzytkowania',
                    'inne produkty w kategorii',
                    'powiazane produkty',
                ], true)) {
                    break;
                }
            }

            if ($current instanceof DOMElement) {
                $fragment = $current->ownerDocument?->saveHTML($current);

                if (is_string($fragment)) {
                    $html .= $fragment;
                }
            } elseif (trim((string) $current->textContent) !== '') {
                $html .= '<p>'.e($this->normalizeText((string) $current->textContent)).'</p>';
            }

            $current = $current->nextSibling;
            $captured++;
        }

        $html = $this->sanitizeHtml($html, $sourceUrl);
        $plain = $this->normalizeText(strip_tags($html));

        return mb_strlen($plain) >= 40 ? $html : null;
    }

    private function sanitizeHtml(string $html, string $sourceUrl): string
    {
        $html = mb_scrub($html, 'UTF-8');
        $html = preg_replace('#<script\b[^>]*>.*?</script>#isu', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace_callback('/\s+(src)\s*=\s*(?P<quote>["\'])(?P<url>[^"\']+)\k<quote>/iu', function (array $match) use ($sourceUrl): string {
            $absolute = $this->normalizeImageUrl((string) ($match['url'] ?? ''), $sourceUrl);

            return $absolute !== null ? ' src="'.e($absolute).'"' : '';
        }, $html) ?? $html;

        return trim(mb_scrub($html, 'UTF-8'));
    }

    /**
     * @return list<array{url: string, alt: string}>
     */
    private function images(Crawler $crawler, string $sourceUrl, string $html): array
    {
        $structuredGalleryImages = $this->structuredGalleryImages($html, $sourceUrl);

        if ($structuredGalleryImages !== []) {
            return $structuredGalleryImages;
        }

        $images = [];

        foreach (['img[src]', 'a[href]'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $sourceUrl, $selector): void {
                    $raw = $selector === 'img[src]'
                        ? (string) $node->attr('src')
                        : (string) $node->attr('href');

                    $url = $this->normalizeImageUrl($raw, $sourceUrl);

                    if ($url === null) {
                        return;
                    }

                    $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

                    if (! str_contains($path, '/_images/produkty/')
                        || str_contains($path, '/.miniatury/')) {
                        return;
                    }

                    $alt = $selector === 'img[src]'
                        ? $this->normalizeText((string) $node->attr('alt'))
                        : '';

                    $images[$url] = [
                        'url' => $url,
                        'alt' => $alt,
                    ];
                });
            } catch (Throwable) {
                continue;
            }
        }

        if (preg_match_all(
            '#(?:https?://'.preg_quote(self::HOST, '#').')?(/_images/produkty/[^\s"\'<>]+?\.(?:jpe?g|png|webp|gif|avif))#iu',
            mb_scrub($html, 'UTF-8'),
            $matches,
        ) === 1 || ($matches[1] ?? []) !== []) {
            foreach ($matches[1] ?? [] as $path) {
                if (! is_string($path)) {
                    continue;
                }

                $url = $this->normalizeImageUrl($path, $sourceUrl);

                if ($url === null) {
                    continue;
                }

                $images[$url] ??= [
                    'url' => $url,
                    'alt' => '',
                ];
            }
        }

        return array_values($images);
    }

    /**
     * Prefer Iconic's structured gallery metadata when present. The page may
     * contain description illustrations and related-product images elsewhere,
     * so the first gallery productId is treated as the current product and
     * only images carrying that same productId are returned.
     *
     * src is preferred over srcBig because Iconic has live gallery rows where
     * srcBig is malformed while src is the canonical full-size path. srcMin is
     * retained as a download fallback because some published full-size paths
     * return 404 even though the product thumbnail is available.
     *
     * @return list<array{url: string, alt: string, fallback_url?: string}>
     */
    private function structuredGalleryImages(string $html, string $sourceUrl): array
    {
        $text = html_entity_decode(
            strip_tags(mb_scrub($html, 'UTF-8')),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        if (preg_match_all('/Array\s*\((.*?)\)\s*1/isu', $text, $matches) !== 1
            && ($matches[1] ?? []) === []) {
            return [];
        }

        $productId = null;
        $images = [];

        foreach ($matches[1] ?? [] as $block) {
            if (! is_string($block)
                || preg_match('/\[productId\]\s*=>\s*(\d+)/iu', $block, $productMatch) !== 1) {
                continue;
            }

            $blockProductId = $productMatch[1];

            if ($productId === null) {
                $productId = $blockProductId;
            }

            if ($blockProductId !== $productId) {
                continue;
            }

            $raw = null;

            foreach (['src', 'srcBig'] as $key) {
                if (preg_match(
                    '/\['.preg_quote($key, '/').'\]\s*=>\s*([^\s\)]+)/iu',
                    $block,
                    $srcMatch,
                ) === 1) {
                    $raw = $srcMatch[1];
                    break;
                }
            }

            if ($raw === null) {
                continue;
            }

            $url = $this->normalizeImageUrl($raw, $sourceUrl);

            if ($url === null) {
                continue;
            }

            $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

            if (! str_contains($path, '/_images/produkty/')
                || str_contains($path, '/.miniatury/')) {
                continue;
            }

            $fallbackUrl = null;

            if (preg_match(
                '/\[srcMin\]\s*=>\s*([^\s\)]+)/iu',
                $block,
                $fallbackMatch,
            ) === 1) {
                $fallbackUrl = $this->normalizeImageUrl($fallbackMatch[1], $sourceUrl);

                if ($fallbackUrl === $url) {
                    $fallbackUrl = null;
                }
            }

            $alt = '';

            if (preg_match(
                '/\[alt\]\s*=>\s*(.*?)(?=\s*\[[^\]]+\]\s*=>|$)/isu',
                $block,
                $altMatch,
            ) === 1) {
                $alt = $this->normalizeText($altMatch[1]);
            }

            $images[$url] = array_filter([
                'url' => $url,
                'alt' => $alt,
                'fallback_url' => $fallbackUrl,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return array_values($images);
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function downloads(Crawler $crawler, string $sourceUrl): array
    {
        $downloads = [];

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$downloads, $sourceUrl): void {
                $href = (string) $node->attr('href');
                $url = $this->normalizeAbsoluteUrl($href, $sourceUrl);

                if ($url === null) {
                    return;
                }

                $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

                if (preg_match('/\.(?:pdf|doc|docx|xls|xlsx|zip)$/iu', $path) !== 1) {
                    return;
                }

                $downloads[$url] = [
                    'label' => $this->normalizeText($node->text('', false)),
                    'url' => $url,
                ];
            });
        } catch (Throwable) {
            return [];
        }

        return array_values($downloads);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variantCandidates(Crawler $crawler, ?float $priceGrossAmount): array
    {
        $root = $crawler->getNode(0);

        if (! $root instanceof DOMNode) {
            return [];
        }

        $document = $root instanceof DOMDocument
            ? $root
            : $root->ownerDocument;

        if (! $document instanceof DOMDocument) {
            return [];
        }

        $variants = [];

        foreach ($document->getElementsByTagName('select') as $selectNode) {
            if (! $selectNode instanceof DOMElement) {
                continue;
            }

            $options = [];

            foreach ($selectNode->getElementsByTagName('option') as $optionNode) {
                if (! $optionNode instanceof DOMElement) {
                    continue;
                }

                $label = $this->normalizeText($optionNode->textContent ?? '');
                $value = $this->normalizeText($optionNode->getAttribute('value'));
                $comparableLabel = $this->comparable($label);

                if ($label === ''
                    || str_contains($comparableLabel, 'wybierz opcje')
                    || str_contains($comparableLabel, 'wybierz')) {
                    continue;
                }

                $options[] = [
                    'value' => $value,
                    'label' => $label,
                ];
            }

            if ($options === []) {
                continue;
            }

            $attributeLabel = $this->selectLabelFromDom($document, $selectNode) ?? 'Wariant';

            foreach ($options as $index => $option) {
                $externalId = $option['value'] !== ''
                    ? $option['value']
                    : (Str::slug($option['label']) ?: 'option-'.($index + 1));

                $key = mb_strtolower($attributeLabel.'|'.$externalId);

                $variants[$key] = [
                    'external_variant_id' => $externalId,
                    'label' => $attributeLabel.': '.$option['label'],
                    'attributes' => [
                        [
                            'label' => $attributeLabel,
                            'value' => $option['label'],
                        ],
                    ],
                    'price_gross_amount' => $priceGrossAmount,
                    'currency' => 'PLN',
                ];
            }
        }

        return array_values($variants);
    }

    private function selectLabelFromDom(DOMDocument $document, DOMElement $select): ?string
    {
        $id = $this->normalizeText($select->getAttribute('id'));

        if ($id !== '') {
            foreach ($document->getElementsByTagName('label') as $labelNode) {
                if (! $labelNode instanceof DOMElement
                    || $this->normalizeText($labelNode->getAttribute('for')) !== $id) {
                    continue;
                }

                $text = rtrim($this->normalizeText($labelNode->textContent ?? ''), ':');

                if ($text !== '') {
                    return $text;
                }
            }
        }

        $name = $this->normalizeText($select->getAttribute('name'));
        $candidate = $name !== '' ? $name : $id;
        $comparable = $this->comparable($candidate);

        if (str_contains($comparable, 'rozmiar') || str_contains($comparable, 'size')) {
            return 'Rozmiar';
        }

        if (str_contains($comparable, 'kolor') || str_contains($comparable, 'color')) {
            return 'Kolor';
        }

        if (str_contains($comparable, 'strona') || str_contains($comparable, 'side')) {
            return 'Strona';
        }

        $contextNode = $select->parentNode;

        for ($depth = 0; $contextNode instanceof DOMNode && $depth < 3; $depth++, $contextNode = $contextNode->parentNode) {
            $context = $this->comparable($contextNode->textContent ?? '');

            if (str_contains($context, 'rozmiar')) {
                return 'Rozmiar';
            }

            if (str_contains($context, 'kolor')) {
                return 'Kolor';
            }

            if (str_contains($context, 'strona')) {
                return 'Strona';
            }
        }

        return null;
    }

    /**
     * @return array{is_medical_device: bool, class: string|null, evidence: string|null}
     */
    private function medicalDeviceEvidence(string $text): array
    {
        $normalized = $this->normalizeText($text);

        if (preg_match('/\bwyr[oó]b\s+medyczny\b/iu', $normalized, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [
                'is_medical_device' => false,
                'class' => null,
                'evidence' => null,
            ];
        }

        $offset = (int) ($match[0][1] ?? 0);
        $evidence = mb_substr($normalized, max(0, $offset - 80), 240);
        $class = null;

        if (preg_match('/\bklas[ay]?\s+(I{1,3}|IV|V)\b/iu', $normalized, $classMatch) === 1) {
            $class = strtoupper($classMatch[1]);
        }

        return [
            'is_medical_device' => true,
            'class' => $class,
            'evidence' => $evidence !== '' ? $evidence : null,
        ];
    }

    /**
     * @return array{code: string, label: string, value: string, slug: string}
     */
    private function attribute(string $label, string $value): array
    {
        return [
            'code' => Str::slug($label) ?: substr(sha1($label), 0, 10),
            'label' => $label,
            'value' => $value,
            'slug' => Str::slug($value) ?: substr(sha1($value), 0, 10),
        ];
    }

    private function shortDescription(?string $seoDescription, string $descriptionPlain): ?string
    {
        $value = $this->normalizeText((string) $seoDescription);

        if ($value === '') {
            $value = $descriptionPlain;
        }

        if ($value === '') {
            return null;
        }

        return Str::limit($value, 320, '');
    }

    private function externalProductIdFromUrl(string $url): ?string
    {
        $slug = $this->slugFromUrl($url);

        return $slug !== '' ? $slug : null;
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = (string) basename($path);

        return preg_replace('/\.html$/iu', '', $basename) ?? $basename;
    }

    private function normalizeImageUrl(string $url, string $baseUrl): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null) {
            return null;
        }

        $path = (string) parse_url($absolute, PHP_URL_PATH);

        if (preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/iu', $path) !== 1) {
            return null;
        }

        return $absolute;
    }

    private function normalizeAbsoluteUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === ''
            || str_starts_with($url, '#')
            || preg_match('/^(?:mailto|tel|javascript):/iu', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::HOST.$url;
        } elseif (! preg_match('#^https?://#iu', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $parts = parse_url($baseUrl);
            $basePath = (string) ($parts['path'] ?? '/');
            $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
            $url = 'https://'.self::HOST
                .($directory === '' || $directory === '.' ? '' : $directory)
                .'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./iu', '', $host) ?? $host;

        if ($host !== self::HOST) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = $this->removeDotSegments($path);
        $path = rtrim($path, '/') ?: '/';

        return 'https://'.self::HOST.($path === '/' ? '' : $path);
    }

    private function removeDotSegments(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function containsComparable(string $haystack, string $needle): bool
    {
        return str_contains($this->comparable($haystack), $this->comparable($needle));
    }

    private function comparable(string $value): string
    {
        $value = Str::ascii($this->normalizeText($value));
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalizeText(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_scrub($value, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function text(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                return null;
            }

            $text = $this->normalizeText($node->text('', false));

            return $text !== '' ? $text : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function attr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                return null;
            }

            $value = $this->normalizeText((string) $node->attr($attribute));

            return $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function metaContent(Crawler $crawler, string $selector): ?string
    {
        return $this->attr($crawler, $selector, 'content');
    }

    private function cssEscape(string $value): string
    {
        return str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $value,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.6',
            'User-Agent' => 'KonjiShopCatalogCrawler/1.0 (+https://ortezka.pl)',
        ];
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
