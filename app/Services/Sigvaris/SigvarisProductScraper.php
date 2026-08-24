<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use DOMElement;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

final class SigvarisProductScraper extends SigvarisHttpClient
{
    /** @return array<string, mixed>|null */
    public function scrape(string $url): ?array
    {
        $failed = [];
        $html = $this->fetchBody($url, $failed);
        if ($html === null) {
            return null;
        }

        return $this->parse($html, $url);
    }

    /** @return array<string, mixed> */
    public function scrapeOrFail(string $url): array
    {
        $failed = [];
        $html = $this->fetchBody($url, $failed);

        if ($html === null) {
            throw new RuntimeException($failed[$url] ?? 'Sigvaris product request failed.');
        }

        return $this->parse($html, $url);
    }

    /** @return array<string, mixed> */
    public function parse(string $html, string $sourceUrl): array
    {
        $sourceUrl = $this->normalizeSiteUrl($sourceUrl, 'https://'.self::CANONICAL_HOST.'/') ?? $sourceUrl;
        $crawler = new Crawler($html, $sourceUrl);
        $identity = $this->productIdentity($sourceUrl);
        $canonical = $this->canonicalUrl($crawler, $sourceUrl);
        $name = $this->firstText($crawler, ['h1', '.product-name', '[itemprop="name"]']);
        $displayPrice = $this->firstPrice($crawler);
        [$gross, $net] = $this->taxPricesFromHistory($html);
        $gross ??= $displayPrice;
        $net ??= null;

        $bodyText = $this->normalizeText($crawler->filter('body')->text(''));
        $stockQuantity = $this->stockQuantity($bodyText);
        $availability = $stockQuantity !== null && $stockQuantity > 0
            ? 'in_stock'
            : (preg_match('/dostępny\s+na\s+zamówienie/ui', $bodyText) === 1 ? 'on_order' : 'unknown');

        $reference = $this->reference($crawler, $bodyText);
        $attributes = $this->variantAttributes($crawler);
        $currentAttributes = [];
        foreach ($attributes as $attribute) {
            if (($attribute['selected'] ?? null) !== null) {
                $currentAttributes[] = [
                    'label' => $attribute['label'],
                    'value' => $attribute['selected'],
                ];
            }
        }

        $features = $this->features($crawler);
        $images = $this->images($crawler, $sourceUrl);
        $downloads = $this->downloads($crawler, $sourceUrl);
        $sizeChart = $this->sizeChart($crawler, $sourceUrl);
        $descriptionHtml = $this->descriptionHtml($crawler);
        $manufacturer = $this->manufacturer($bodyText);
        $medicalDevice = preg_match('/WAŻNE:\s*To\s+jest\s+wyrób\s+medyczny/ui', $bodyText) === 1 ? true : null;

        $warnings = [];
        if ($identity['combination_id'] !== null && $attributes !== []) {
            $warnings[] = 'Only the currently rendered PrestaShop combination is observed on the product HTML; full concrete combination enumeration will be validated in a later mapping stage.';
        }
        if ($gross === null) {
            $warnings[] = 'Gross price could not be resolved from price history or visible product price.';
        }
        if ($images === []) {
            $warnings[] = 'No product images were discovered.';
        }

        $taxRate = null;
        if ($gross !== null && $net !== null && $net > 0) {
            $taxRate = round((($gross / $net) - 1) * 100, 2);
        }

        $observedCombination = $identity['combination_id'] !== null ? [
            'external_variant_id' => $identity['combination_id'],
            'reference' => $reference,
            'price_gross_amount' => $gross,
            'price_net_amount' => $net,
            'currency' => 'PLN',
            'availability' => $availability,
            'attributes' => $currentAttributes,
        ] : null;

        return [
            'source' => 'sigvaris',
            'platform' => 'prestashop',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonical,
            'external_product_id' => $identity['product_id'],
            'default_combination_id' => $identity['combination_id'],
            'name' => $name,
            'reference' => $reference,
            'display_price_amount' => $displayPrice,
            'price_gross_amount' => $gross,
            'price_net_amount' => $net,
            'tax_rate_percent' => $taxRate,
            'currency' => 'PLN',
            'availability' => $availability,
            'stock_quantity' => $stockQuantity,
            'is_medical_device' => $medicalDevice,
            'manufacturer' => $manufacturer,
            'attributes' => $attributes,
            'features' => $features,
            'observed_combination' => $observedCombination,
            'variant_candidates' => $observedCombination !== null ? [$observedCombination] : [],
            'images' => $images,
            'downloads' => $downloads,
            'size_chart' => $sizeChart,
            'description_html' => $descriptionHtml,
            'warnings' => $warnings,
        ];
    }

    /** @return array{product_id:string,combination_id:string|null} */
    private function productIdentity(string $url): array
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('#^/(\d+)(?:-(\d+))?-([^/]+)\.html$#u', $path, $m) === 1) {
            return [
                'product_id' => $m[1],
                'combination_id' => isset($m[2]) && $m[2] !== '' ? $m[2] : null,
            ];
        }
        return ['product_id' => sha1($url), 'combination_id' => null];
    }

    private function canonicalUrl(Crawler $crawler, string $fallback): string
    {
        $node = $crawler->filter('link[rel="canonical"]')->first();
        if ($node->count() > 0) {
            $href = $node->attr('href');
            if (is_string($href)) {
                return $this->normalizeSiteUrl($href, $fallback) ?? $fallback;
            }
        }
        return preg_replace('/\?.*$/', '', $fallback) ?: $fallback;
    }

    /** @param array<int,string> $selectors */
    private function firstText(Crawler $crawler, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }
            $value = $this->normalizeText($node->text(''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function firstPrice(Crawler $crawler): ?float
    {
        foreach ([
            '.current-price .current-price-value',
            '.current-price [itemprop="price"]',
            '.current-price span',
            '[itemprop="price"]',
        ] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }
            $content = $node->attr('content');
            $value = is_string($content) && $content !== '' ? $content : $node->text('');
            $parsed = $this->parseMoney((string) $value);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    /** @return array{0:float|null,1:float|null} */
    private function taxPricesFromHistory(string $html): array
    {
        $normalized = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = str_replace(['\\&quot;', '\\"'], '"', $normalized);

        $grossMatches = [];
        $netMatches = [];

        preg_match_all(
            '/"price_tax_included"\s*:\s*"?([0-9]+(?:\.[0-9]+)?)"?/',
            $normalized,
            $grossMatches,
        );

        preg_match_all(
            '/"price_tax_excluded"\s*:\s*"?([0-9]+(?:\.[0-9]+)?)"?/',
            $normalized,
            $netMatches,
        );

        $grossValues = $grossMatches[1] ?? [];
        $netValues = $netMatches[1] ?? [];

        if ($grossValues === [] || $netValues === []) {
            return [null, null];
        }

        return [
            (float) $grossValues[array_key_last($grossValues)],
            (float) $netValues[array_key_last($netValues)],
        ];
    }

    private function parseMoney(string $value): ?float
    {
        $value = str_replace(["\xc2\xa0", ' ', 'zł', 'PLN'], '', $value);
        $value = str_replace(',', '.', $value);
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m) !== 1) {
            return null;
        }
        return (float) $m[0];
    }

    private function stockQuantity(string $text): ?int
    {
        if (preg_match('/W\s+magazynie\s+(\d+)\s+(?:Przedmioty|Przedmiotów|szt)/ui', $text, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    private function reference(Crawler $crawler, string $bodyText): ?string
    {
        foreach (['.product-reference span', '[itemprop="sku"]', '.product-reference'] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }
            $value = $this->normalizeText($node->text(''));
            $value = preg_replace('/^(Indeks|Reference|SKU)\s*:?\s*/ui', '', $value) ?? $value;
            if ($value !== '') {
                return $value;
            }
        }
        if (preg_match('/\bIndeks\s+([A-Za-z0-9._\/-]+?)(?=W\s+magazynie\b|\s|$)/u', $bodyText, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** @return array<int,array{label:string,options:array<int,string>,selected:string|null}> */
    private function variantAttributes(Crawler $crawler): array
    {
        $attributes = [];
        $crawler->filter('.product-variants .product-variants-item')->each(function (Crawler $item) use (&$attributes): void {
            $label = $this->firstText($item, ['.control-label', 'label', '.form-control-label']);
            if ($label === null) {
                return;
            }
            $label = rtrim($label, ': ');
            $options = [];
            $selected = null;

            $item->filter('option')->each(function (Crawler $option) use (&$options, &$selected): void {
                $text = $this->normalizeText($option->text(''));
                if ($text === '') {
                    return;
                }
                $options[] = $text;
                $node = $option->getNode(0);
                if ($node instanceof DOMElement && $node->hasAttribute('selected')) {
                    $selected = $text;
                }
            });

            $item->filter('input')->each(function (Crawler $input) use (&$options, &$selected): void {
                $node = $input->getNode(0);
                if (! $node instanceof DOMElement) {
                    return;
                }
                $id = $node->getAttribute('id');
                $text = '';
                if ($id !== '') {
                    $labelNode = $input->ancestors()->last()->filter('label[for="'.$id.'"]')->first();
                    if ($labelNode->count() > 0) {
                        $text = $this->normalizeText($labelNode->text(''));
                    }
                }
                $text = $text !== '' ? $text : $this->normalizeText($node->getAttribute('title'));
                if ($text === '') {
                    $text = $this->normalizeText($node->getAttribute('value'));
                }
                if ($text !== '') {
                    $options[] = $text;
                    if ($node->hasAttribute('checked')) {
                        $selected = $text;
                    }
                }
            });

            $attributes[] = [
                'label' => $label,
                'options' => array_values(array_unique($options)),
                'selected' => $selected,
            ];
        });
        return $attributes;
    }

    /** @return array<int,array{label:string,value:string}> */
    private function features(Crawler $crawler): array
    {
        $features = [];
        foreach ($crawler->filter('dl.data-sheet, .product-features dl') as $dlNode) {
            if (! $dlNode instanceof DOMElement) {
                continue;
            }
            $pending = null;
            foreach ($dlNode->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'dt') {
                    $pending = $this->normalizeText($child->textContent ?? '');
                } elseif ($tag === 'dd' && $pending !== null) {
                    $value = $this->normalizeText($child->textContent ?? '');
                    if ($pending !== '' && $value !== '') {
                        $features[] = ['label' => $pending, 'value' => $value];
                    }
                    $pending = null;
                }
            }
        }
        return $features;
    }

    /** @return array<int,array{url:string,alt:string|null}> */
    private function images(Crawler $crawler, string $baseUrl): array
    {
        $images = [];
        foreach (['.product-cover img', '.product-images img', 'img.js-thumb', '.images-container img'] as $selector) {
            $crawler->filter($selector)->each(function (Crawler $image) use (&$images, $baseUrl): void {
                $node = $image->getNode(0);
                if (! $node instanceof DOMElement) {
                    return;
                }
                foreach (['data-image-large-src', 'data-full-size-image-url', 'data-src', 'src'] as $attribute) {
                    $raw = $node->getAttribute($attribute);
                    if ($raw === '') {
                        continue;
                    }
                    $url = $this->absoluteAssetUrl($raw, $baseUrl);
                    if ($url === null) {
                        continue;
                    }
                    $images[$url] = [
                        'url' => $url,
                        'alt' => ($alt = $this->normalizeText($node->getAttribute('alt'))) !== '' ? $alt : null,
                    ];
                    break;
                }
            });
        }
        return array_values($images);
    }

    /** @return array<int,array{url:string,label:string|null}> */
    private function downloads(Crawler $crawler, string $baseUrl): array
    {
        $downloads = [];
        foreach ([
            '#attachments a[href]',
            '.product-attachments a[href]',
            '.attachments a[href]',
            'a[href*="/module/prestadogpsrmanager/download"]',
            'a[href*="module/prestadogpsrmanager/download"]',
            '#description a[href]',
            '.product-description a[href]',
        ] as $selector) {
            $crawler->filter($selector)->each(function (Crawler $link) use (&$downloads, $baseUrl): void {
                $href = $link->attr('href');
                if (! is_string($href)) {
                    return;
                }
                $url = $this->absoluteAssetUrl($href, $baseUrl);
                if ($url === null) {
                    return;
                }
                $path = strtolower((string) parse_url($url, PHP_URL_PATH));
                if (! str_ends_with($path, '.pdf') && ! str_contains($path, 'attachment') && ! str_contains($path, 'download')) {
                    return;
                }
                $label = $this->normalizeText($link->text(''));
                $downloads[$url] = ['url' => $url, 'label' => $label !== '' ? $label : null];
            });
        }
        return array_values($downloads);
    }

    /** @return array{url:string,label:string}|null */
    private function sizeChart(Crawler $crawler, string $baseUrl): ?array
    {
        $resolved = null;

        $crawler->filter('a[href]')->each(function (Crawler $link) use (&$resolved, $baseUrl): void {
            if ($resolved !== null) {
                return;
            }

            $label = $this->normalizeText($link->text(''));

            if (preg_match('/tabela\s+rozmiarów/ui', $label) !== 1) {
                return;
            }

            $href = $link->attr('href');
            $url = is_string($href) ? $this->absoluteAssetUrl($href, $baseUrl) : null;

            if ($url !== null && $this->isSizeChartImageUrl($url)) {
                $resolved = ['url' => $url, 'label' => 'TABELA ROZMIARÓW'];
            }
        });

        if ($resolved !== null) {
            return $resolved;
        }

        $descriptionText = $this->normalizeText($crawler->filter('#description, .product-description')->first()->text(''));

        if (preg_match('/tabela\s+rozmiarów/ui', $descriptionText) !== 1) {
            return null;
        }

        $crawler->filter('#description img[src], .product-description img[src]')->each(function (Crawler $image) use (&$resolved, $baseUrl): void {
            if ($resolved !== null) {
                return;
            }

            $node = $image->getNode(0);

            if (! $node instanceof DOMElement) {
                return;
            }

            $context = $this->normalizeText($node->getAttribute('alt').' '.$node->getAttribute('title'));
            $raw = $node->getAttribute('src');
            $url = $raw !== '' ? $this->absoluteAssetUrl($raw, $baseUrl) : null;

            if ($url === null || ! $this->isSizeChartImageUrl($url)) {
                return;
            }

            if (preg_match('/tabela\s+rozmiarów/ui', $context) === 1 || str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), '/img/cms/')) {
                $resolved = ['url' => $url, 'label' => 'TABELA ROZMIARÓW'];
            }
        });

        return $resolved;
    }

    private function isSizeChartImageUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, '/img/cms/')
            && preg_match('/\.(?:png|jpe?g|webp|gif|svg)$/i', $path) === 1;
    }

    private function descriptionHtml(Crawler $crawler): ?string
    {
        foreach (['#description .product-description', '#description', '.product-description'] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }
            $html = trim($node->html(''));
            if ($html !== '') {
                return $html;
            }
        }
        return null;
    }

    private function manufacturer(string $bodyText): ?string
    {
        if (preg_match('/Producent\s*\/\s*Importer\s+(.+?)\s+Ulica:/ui', $bodyText, $m) === 1) {
            return $this->normalizeText($m[1]);
        }
        return null;
    }

    private function absoluteAssetUrl(string $raw, string $baseUrl): ?string
    {
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($raw === '' || str_starts_with($raw, 'data:')) {
            return null;
        }
        if (str_starts_with($raw, '//')) {
            return 'https:'.$raw;
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        return $parts['scheme'].'://'.$parts['host'].'/'.ltrim($raw, '/');
    }
}
