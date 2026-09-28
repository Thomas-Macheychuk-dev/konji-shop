<?php

declare(strict_types=1);

namespace App\Services\DrSapporo;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class DrSapporoProductScraper
{
    private const HOST = 'drsapporo.com';

    /**
     * Supplier/manufacturer documentation confirms these products as
     * class-I medical devices even when the reseller product page omits
     * the explicit medical-device wording.
     *
     * @var list<string>
     */
    private const REVIEWED_MEDICAL_DEVICE_EXTERNAL_IDS = [
        'poduszka-ortopedyczna-asana',
        'poduszka-ortopedyczna-enso',
        'poduszka-ortopedyczna-hiro',
        'aparat-na-haluksy-bunito-duo-ecru',
        'aparat-na-haluksy-bunito-duo-magenta',
        'aparat-na-haluksy-bunito-duo-turkus',
    ];

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
        $url = $this->normalizeProductUrl($url);

        if ($url === null) {
            return $this->failedPayload($url ?? '', 'Invalid Dr Sapporo product URL.');
        }

        $this->pauseBeforeRequest();
        $this->emit('Fetching Dr Sapporo product page: '.$url);

        try {
            $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->withHeaders($this->headers())
                ->get($url);
        } catch (Throwable $exception) {
            return $this->failedPayload($url, $exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->failedPayload($url, 'HTTP '.$response->status());
        }

        return $this->extract($response->body(), $url, $context);
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
            return $this->failedPayload($sourceUrl, 'Unable to parse Dr Sapporo product HTML.');
        }

        $canonicalUrl = $this->canonicalUrl($crawler, $sourceUrl);
        $slug = $this->slugFromUrl($canonicalUrl);
        $name = $this->normalizeProductName(
            $this->firstText($crawler, [
                'h1',
                '[itemprop="name"]',
                '.product-name',
                '.product-title',
            ]) ?? $this->contextString($context, 'name') ?? Str::headline($slug)
        );

        $brandName = $this->contextString($context, 'brand_name')
            ?? $this->inferBrand($name, $html);
        $categoryName = $this->contextString($context, 'category_name')
            ?? $this->breadcrumbCategory($crawler);

        $bodyText = $this->extractBodyText($crawler, $html);
        $price = $this->extractPrice($crawler, $bodyText);
        $shippingTime = $this->extractShippingTime($crawler, $bodyText);
        $availability = $this->extractAvailability($bodyText);
        $sku = $this->extractLabelledValue($crawler, $bodyText, ['sku', 'kod produktu', 'symbol']);
        $ean = $this->extractLabelledValue($crawler, $bodyText, ['ean', 'gtin']);

        $images = $this->extractImages($crawler, $canonicalUrl);
        $attributes = $this->extractAttributes($crawler, $bodyText);
        $variantCandidates = $this->extractVariants($crawler, $price);
        $descriptionHtml = $this->extractDescriptionHtml($crawler, $bodyText);
        $seoDescription = $this->firstAttr($crawler, 'meta[name="description"]', 'content')
            ?? $this->seoDescriptionFallback($descriptionHtml);

        return [
            'source' => 'drsapporo',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $slug,
            'slug' => $slug,
            'name' => $name,
            'brand' => [
                'name' => $brandName,
                'slug' => Str::slug($brandName),
            ],
            'category' => $categoryName,
            'categories' => $categoryName !== null ? [$categoryName] : [],
            'source_category_name' => $this->contextString($context, 'category_name'),
            'source_product_list_name' => $this->contextString($context, 'name'),
            'seo_description' => $seoDescription,
            'description_html' => $descriptionHtml,
            'price_gross_amount' => $price,
            'currency' => 'PLN',
            'availability' => $availability['code'],
            'availability_label' => $availability['label'],
            'shipping_time' => $shippingTime,
            'sku' => $sku,
            'ean' => $ean,
            'is_medical_device' => preg_match('/\bwyr[oó]b(?:em)?\s+medyczn/iu', $bodyText) === 1
                || in_array($slug, self::REVIEWED_MEDICAL_DEVICE_EXTERNAL_IDS, true),
            'images' => $images,
            'attributes' => $attributes,
            'variant_candidates' => $variantCandidates,
            'warnings' => [],
            'failed_urls' => [],
        ];
    }

    public function normalizeProductUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::HOST.$url;
        }

        $parts = parse_url($url);

        if (! isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./iu', '', $host) ?? $host;

        if ($host !== self::HOST) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $path = rtrim(preg_replace('#/+#', '/', $path) ?? $path, '/');

        if ($path === '' || preg_match('#^/(?:poduszka|poszewka|aparat)-#iu', $path) !== 1) {
            return null;
        }

        return 'https://'.self::HOST.$path;
    }

    private function canonicalUrl(Crawler $crawler, string $fallback): string
    {
        $canonical = $this->firstAttr($crawler, 'link[rel="canonical"]', 'href');

        return $canonical !== null
            ? ($this->normalizeProductUrl($canonical) ?? $fallback)
            : $fallback;
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = basename($path);

        return $slug !== '' ? $slug : substr(sha1($url), 0, 32);
    }

    private function normalizeProductName(string $name): string
    {
        $name = preg_replace(
            '/(?<=[\\p{L}\\p{N})])(?=(?:Poduszka|Poszewka|Aparat)\\b)/u',
            ' ',
            $name,
        ) ?? $name;

        return $this->text($name);
    }

    private function inferBrand(string $name, string $html): string
    {
        $haystack = Str::ascii($name.' '.strip_tags($html));

        return preg_match('/\bONSEN\b/iu', $haystack) === 1
            ? 'ONSEN'
            : 'Dr Sapporo';
    }

    private function breadcrumbCategory(Crawler $crawler): ?string
    {
        foreach ([
            '.breadcrumbs a',
            '.breadcrumb a',
            'nav[aria-label*="breadcrumb"] a',
            '.path a',
        ] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            if ($nodes->count() < 2) {
                continue;
            }

            $values = [];

            $nodes->each(function (Crawler $node) use (&$values): void {
                $value = $this->text($node->text(''));

                if ($value !== '' && ! in_array($this->comparable($value), ['strona glowna', 'produkty'], true)) {
                    $values[] = $value;
                }
            });

            if ($values !== []) {
                return end($values) ?: null;
            }
        }

        return null;
    }

    private function extractBodyText(Crawler $crawler, string $html): string
    {
        try {
            $body = $crawler->filter('body')->first();
            $node = $body->getNode(0);

            if ($node instanceof \DOMNode) {
                $text = $this->nodeTextWithBoundaries($node);

                if ($text !== '') {
                    return $text;
                }
            }
        } catch (Throwable) {
            // Fall back to HTML stripping below.
        }

        $withBoundaries = preg_replace('/<[^>]+>/u', ' ', $html) ?? $html;

        return $this->text(strip_tags($withBoundaries));
    }

    private function nodeTextWithBoundaries(\DOMNode $node): string
    {
        if ($node instanceof DOMElement
            && in_array(mb_strtolower($node->tagName), ['script', 'style', 'noscript', 'template'], true)) {
            return '';
        }

        if ($node instanceof \DOMText) {
            return $this->text($node->nodeValue ?? '');
        }

        $parts = [];

        foreach ($node->childNodes as $child) {
            $text = $this->nodeTextWithBoundaries($child);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $this->text(implode(' ', $parts));
    }

    private function extractPrice(Crawler $crawler, string $bodyText): ?float
    {
        foreach ([
            '[itemprop="price"]',
            'meta[itemprop="price"]',
            '.main-price',
            '.product-price',
            '.price-value',
            '.price',
        ] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $candidate = $node->getAttribute('content') ?: ($node->textContent ?? '');
                $price = $this->money($candidate);

                if ($price !== null) {
                    return $price;
                }
            }
        }

        if (preg_match('/\b(\d{1,5}[,.]\d{2})\s*(?:zł|pln)\b/iu', $bodyText, $matches) === 1) {
            return $this->money($matches[1]);
        }

        return null;
    }

    private function extractShippingTime(Crawler $crawler, string $bodyText): ?string
    {
        foreach (['.term', '.productPageName .term'] as $selector) {
            $value = $this->firstText($crawler, [$selector]);

            if ($value === null) {
                continue;
            }

            if (preg_match('/Termin\s*realizacji\s*:?\s*([0-9]+\s+(?:dzień|dni|godzin(?:a|y)?)(?:\s+robocz(?:y|e|ych))?)/iu', $value, $matches) === 1) {
                return $this->text($matches[1]);
            }
        }

        if (preg_match('/Termin\s*realizacji\s*:?\s*([0-9]+\s+(?:dzień|dni|godzin(?:a|y)?)(?:\s+robocz(?:y|e|ych))?)/iu', $bodyText, $matches) === 1) {
            return $this->text($matches[1]);
        }

        return null;
    }

    /**
     * @return array{code: string, label: string|null}
     */
    private function extractAvailability(string $bodyText): array
    {
        $comparable = $this->comparable($bodyText);

        if (preg_match('/\b(brak w magazynie|niedostepn|wyprzedan)\b/u', $comparable) === 1) {
            return ['code' => 'out_of_stock', 'label' => 'Niedostępny'];
        }

        if (str_contains($comparable, 'na zamowienie')) {
            return ['code' => 'preorder', 'label' => 'Na zamówienie'];
        }

        if (str_contains($comparable, 'dodaj do koszyka') || str_contains($comparable, 'termin realizacji')) {
            return ['code' => 'in_stock', 'label' => 'Dostępny'];
        }

        return ['code' => 'unknown', 'label' => null];
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function extractLabelledValue(Crawler $crawler, string $bodyText, array $labels): ?string
    {
        foreach ($labels as $label) {
            $itemProp = match ($label) {
                'sku' => 'sku',
                'ean', 'gtin' => 'gtin13',
                default => null,
            };

            if ($itemProp !== null) {
                foreach (['[itemprop="'.$itemProp.'"]', 'meta[itemprop="'.$itemProp.'"]'] as $selector) {
                    $value = $this->firstAttr($crawler, $selector, 'content')
                        ?? $this->firstText($crawler, [$selector]);

                    if ($value !== null) {
                        return $value;
                    }
                }
            }

            $quoted = preg_quote($label, '/');

            if (in_array($label, ['ean', 'gtin'], true)) {
                if (preg_match('/\b'.$quoted.'\s*:\s*([0-9]{8,14})\b/iu', $bodyText, $matches) === 1) {
                    return $matches[1];
                }

                continue;
            }

            if (preg_match(
                '/\b'.$quoted.'\s*:\s*([A-Z0-9][A-Z0-9._\/ -]{0,80}?)(?=\s+(?:SKU|Kod\s+produktu|Symbol|EAN|GTIN)\s*:|$)/iu',
                $bodyText,
                $matches,
            ) === 1) {
                return $this->text($matches[1]);
            }
        }

        return null;
    }

    /**
     * @return array<int, array{url: string, alt: string}>
     */
    private function extractImages(Crawler $crawler, string $baseUrl): array
    {
        $images = [];
        $ogImage = $this->firstAttr($crawler, 'meta[property="og:image"]', 'content');

        if ($ogImage !== null) {
            $url = $this->normalizeImageUrl($ogImage, $baseUrl);

            if ($url !== null && $this->isProductImageUrl($url)) {
                $images[$url] = ['url' => $url, 'alt' => ''];
            }
        }

        foreach (['main img', '#content img', '.product img', '.gallery img', 'img[itemprop="image"]'] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $candidate = null;
                $parent = $node->parentElement;

                if ($parent instanceof DOMElement
                    && mb_strtolower($parent->tagName) === 'a'
                    && $parent->hasAttribute('href')) {
                    $candidate = $parent->getAttribute('href');
                }

                foreach (['data-original', 'data-src', 'src'] as $attribute) {
                    if ($candidate === null && $node->hasAttribute($attribute)) {
                        $candidate = $node->getAttribute($attribute);
                    }
                }

                if (! is_string($candidate)) {
                    continue;
                }

                $url = $this->normalizeImageUrl($candidate, $baseUrl);

                if ($url === null || ! $this->isProductImageUrl($url)) {
                    continue;
                }

                $images[$url] = [
                    'url' => $url,
                    'alt' => $this->text($node->getAttribute('alt')),
                ];
            }
        }

        foreach (['.productPhotos .photo', '.productSubPhotos .photo', '.productSubPhoto .photoFrame a'] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $candidates = [];

                if (mb_strtolower($node->tagName) === 'a' && $node->hasAttribute('href')) {
                    $candidates[] = $node->getAttribute('href');
                }

                if ($node->hasAttribute('style')
                    && preg_match('/background-image\s*:\s*url\((["\']?)(.*?)\1\)/iu', $node->getAttribute('style'), $matches) === 1) {
                    $candidates[] = $matches[2];
                }

                foreach ($candidates as $candidate) {
                    $url = $this->normalizeImageUrl((string) $candidate, $baseUrl);

                    if ($url === null || ! $this->isProductImageUrl($url)) {
                        continue;
                    }

                    $images[$url] = [
                        'url' => $url,
                        'alt' => '',
                    ];
                }
            }
        }

        return array_values($images);
    }

    private function isProductImageUrl(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./iu', '', $host) ?? $host;
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host === self::HOST
            && preg_match('#^/photos/product/[^/]+/#iu', $path) === 1;
    }

    private function normalizeImageUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || preg_match('/^(?:data|javascript):/iu', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::HOST.$url;
        } elseif (! preg_match('#^https?://#iu', $url)) {
            $url = rtrim(dirname($baseUrl), '/').'/'.ltrim($url, '/');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/iu', $path) !== 1) {
            return null;
        }

        return $url;
    }

    /**
     * @return array<int, array{code: string, label: string, value: string, slug: string}>
     */
    private function extractAttributes(Crawler $crawler, string $bodyText): array
    {
        $attributes = [];

        foreach (['table tr', 'dl'] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            $nodes->each(function (Crawler $node) use (&$attributes): void {
                $label = null;
                $value = null;

                foreach (['th', 'dt'] as $labelSelector) {
                    if ($node->filter($labelSelector)->count() > 0) {
                        $label = $this->text($node->filter($labelSelector)->first()->text(''));
                        break;
                    }
                }

                foreach (['td', 'dd'] as $valueSelector) {
                    if ($node->filter($valueSelector)->count() > 0) {
                        $value = $this->text($node->filter($valueSelector)->first()->text(''));
                        break;
                    }
                }

                if ($label !== null && $label !== '' && $value !== null && $value !== '') {
                    $attributes[$this->comparable($label).'|'.$this->comparable($value)] = $this->attribute($label, $value);
                }
            });
        }

        try {
            $crawler->filter('li, p')->each(function (Crawler $node) use (&$attributes): void {
                $text = $this->text($node->text(''));

                if (mb_strlen($text) > 160) {
                    return;
                }

                if (preg_match('/^(szerokość|długość|wysokość|rozmiar(?:\s+(?:poduszki|aparatu))?)\s*:\s*(.+)$/iu', $text, $matches) !== 1) {
                    return;
                }

                $attribute = $this->attribute($this->text($matches[1]), $this->text($matches[2]));
                $attributes[$attribute['code'].'|'.$attribute['slug']] = $attribute;
            });
        } catch (Throwable) {
            // Optional semantic extraction only.
        }

        foreach ([
            'szerokość' => 'Szerokość',
            'długość' => 'Długość',
            'wysokość' => 'Wysokość',
        ] as $sourceLabel => $label) {
            $pattern = '/'.preg_quote($sourceLabel, '/').'\\s*:\\s*'
                .'([0-9]+(?:[,.][0-9]+)?(?:\\/[0-9]+(?:[,.][0-9]+)?)?'
                .'\\s*(?:cm|centymetr(?:a|y|ów)?))/iu';

            if (preg_match_all($pattern, $bodyText, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $value) {
                $normalizedValue = preg_replace(
                    '/(?<=[0-9])(?=(?:cm|centymetr(?:a|y|ów)?))/iu',
                    ' ',
                    $this->text((string) $value),
                ) ?? $this->text((string) $value);

                $attribute = $this->attribute($label, $normalizedValue);
                $attributes[$attribute['code'].'|'.$attribute['slug']] = $attribute;
            }
        }

        if (preg_match(
            '/\\bRozmiar\\s+(?:poduszki|aparatu)\\s*:?[\\s-]*'
                .'(uniwersalny\\s*\\((?:jeden\\s+rozmiar|regulowany)\\))/iu',
            $bodyText,
            $matches,
        ) === 1) {
            $attribute = $this->attribute('Rozmiar', $this->text($matches[1]));
            $attributes[$attribute['code'].'|'.$attribute['slug']] = $attribute;
        }

        return array_values($attributes);
    }

    /**
     * @return array{code: string, label: string, value: string, slug: string}
     */
    private function attribute(string $label, string $value): array
    {
        return [
            'code' => Str::slug($label),
            'label' => $label,
            'value' => $value,
            'slug' => Str::slug(str_replace(['/', '\\', ',', '.'], '-', $value)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractVariants(Crawler $crawler, ?float $price): array
    {
        $groups = [];

        try {
            $selects = $crawler->filter('select');
        } catch (Throwable) {
            return [];
        }

        $selects->each(function (Crawler $select) use (&$groups): void {
            $options = [];
            $selectNode = $select->getNode(0);

            if (! $selectNode instanceof DOMElement) {
                return;
            }

            $label = $this->selectLabel($crawler = $select, $selectNode);
            $comparableLabel = $this->comparable($label);

            if ($label === '' || str_contains($comparableLabel, 'ilosc') || str_contains($comparableLabel, 'quantity')) {
                return;
            }

            $select->filter('option')->each(function (Crawler $option) use (&$options): void {
                $text = $this->text($option->text(''));
                $value = trim((string) $option->attr('value'));

                if ($text === ''
                    || $value === ''
                    || preg_match('/^(?:wybierz|select|--)/iu', $text) === 1) {
                    return;
                }

                $options[] = ['id' => $value, 'value' => $text];
            });

            if ($options !== []) {
                $groups[] = ['label' => $label, 'options' => $options];
            }
        });

        if ($groups === []) {
            return [];
        }

        $combinations = [[]];

        foreach ($groups as $group) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($group['options'] as $option) {
                    $next[] = [...$combination, [
                        'label' => $group['label'],
                        'value' => $option['value'],
                        'id' => $option['id'],
                    ]];

                    if (count($next) >= 100) {
                        break 2;
                    }
                }
            }

            $combinations = $next;
        }

        $variants = [];

        foreach ($combinations as $combination) {
            $parts = [];
            $ids = [];
            $attributes = [];

            foreach ($combination as $item) {
                $parts[] = $item['label'].': '.$item['value'];
                $ids[] = $item['id'];
                $attributes[] = [
                    'label' => $item['label'],
                    'value' => $item['value'],
                ];
            }

            $variants[] = [
                'external_variant_id' => implode('-', $ids),
                'label' => implode(' / ', $parts),
                'price_gross_amount' => $price,
                'currency' => 'PLN',
                'attributes' => $attributes,
            ];
        }

        return $variants;
    }

    private function selectLabel(Crawler $select, DOMElement $selectNode): string
    {
        $id = trim($selectNode->getAttribute('id'));

        if ($id !== '') {
            $document = $selectNode->ownerDocument;

            if ($document !== null) {
                $xpath = new \DOMXPath($document);
                $labels = $xpath->query('//label[@for="'.str_replace('"', '', $id).'"]');

                if ($labels !== false && $labels->length > 0) {
                    return trim(preg_replace('/^[*:\s]+|[:\s]+$/u', '', $this->text($labels->item(0)?->textContent ?? '')) ?? '');
                }
            }
        }

        $name = trim($selectNode->getAttribute('name'));

        return $name !== '' ? Str::headline($name) : '';
    }

    private function extractDescriptionHtml(Crawler $crawler, string $bodyText): ?string
    {
        try {
            $specs = $crawler->filter('.productDataSpec');

            foreach ($specs as $specNode) {
                if (! $specNode instanceof DOMElement) {
                    continue;
                }

                $spec = new Crawler($specNode);
                $label = $this->firstText($spec, ['.label']);

                if ($label === null
                    || preg_match('/^Informacje\s+o\s+(?:poduszce|poszewce|aparacie|produkcie)$/iu', $label) !== 1) {
                    continue;
                }

                $contentNode = $spec->filter('.content')->first();

                if ($contentNode->count() === 0) {
                    continue;
                }

                $html = $this->sanitizeHtml($contentNode->html(''));

                if ($html !== null) {
                    return $html;
                }
            }
        } catch (Throwable) {
            // Fall through to generic selectors and text fallback.
        }

        foreach ([
            '[itemprop="description"]',
            '.product-description',
            '#description',
            '.description',
            '.product-content',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() === 0) {
                    continue;
                }

                $html = $this->sanitizeHtml($node->html(''));

                if ($html !== null) {
                    return $html;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $descriptionText = $this->descriptionTextFromBody($bodyText);

        if ($descriptionText !== null) {
            return '<p>'.e($descriptionText).'</p>';
        }

        return null;
    }

    private function descriptionTextFromBody(string $bodyText): ?string
    {
        if (preg_match(
            '/Informacje\s*o\s*(?:poduszce|poszewce|aparacie|produkcie)/iu',
            $bodyText,
            $startMatch,
            PREG_OFFSET_CAPTURE,
        ) !== 1) {
            return null;
        }

        $matchedHeading = (string) $startMatch[0][0];
        $startOffset = (int) $startMatch[0][1] + strlen($matchedHeading);
        $tail = substr($bodyText, $startOffset);

        if ($tail === false) {
            return null;
        }

        $stopOffset = strlen($tail);

        foreach ([
            '/Wymiary\s*(?:poduszki|aparatu)/iu',
            '/Rozmiary?\s*(?:poduszki|poszewki|aparatu)/iu',
            '/Dostawa\s*(?:poduszki|poszewki|aparatu)/iu',
            '/Gwarancja\s*(?:na\s*)?(?:poduszkę|poszewkę|aparat)/iu',
            '/Produkty\s*Zakupy\s*u\s*nas/iu',
            '/Ciasteczka\s*na\s*powitanie/iu',
        ] as $stopPattern) {
            if (preg_match($stopPattern, $tail, $stopMatch, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $stopOffset = min($stopOffset, (int) $stopMatch[0][1]);
        }

        $text = $this->text(substr($tail, 0, $stopOffset));

        if ($text === '') {
            return null;
        }

        return Str::limit($text, 6000, '');
    }

    private function seoDescriptionFallback(?string $descriptionHtml): ?string
    {
        if ($descriptionHtml === null) {
            return null;
        }

        $text = $this->text(strip_tags($descriptionHtml));

        if ($text === '') {
            return null;
        }

        return Str::limit($text, 155, '');
    }

    private function sanitizeHtml(string $html): ?string
    {
        $html = preg_replace('/<(script|style|form|button)\b[^>]*>.*?<\/\1>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = trim($html);

        return $html !== '' ? $html : null;
    }

    /**
     * @param  array<int, string>  $selectors
     */
    private function firstText(Crawler $crawler, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() === 0) {
                    continue;
                }

                $value = $this->text($node->text(''));

                if ($value !== '') {
                    return $value;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                return null;
            }

            $value = trim((string) $node->attr($attribute));

            return $value !== '' ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
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

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function money(string $value): ?float
    {
        $value = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value) ?? $value;

        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }

    private function comparable(string $value): string
    {
        $value = Str::ascii($this->text($value));
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function failedPayload(string $url, string $reason): array
    {
        return [
            'source' => 'drsapporo',
            'source_url' => $url,
            'canonical_url' => $url,
            'external_product_id' => $url !== '' ? $this->slugFromUrl($url) : null,
            'slug' => $url !== '' ? $this->slugFromUrl($url) : null,
            'name' => '',
            'brand' => ['name' => 'Dr Sapporo', 'slug' => 'dr-sapporo'],
            'category' => null,
            'categories' => [],
            'seo_description' => null,
            'description_html' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'shipping_time' => null,
            'sku' => null,
            'ean' => null,
            'is_medical_device' => false,
            'images' => [],
            'attributes' => [],
            'variant_candidates' => [],
            'warnings' => ['Unable to fetch Dr Sapporo product page.'],
            'failed_urls' => $url !== '' ? [$url => $reason] : [],
        ];
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
