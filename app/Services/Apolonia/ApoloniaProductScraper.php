<?php

declare(strict_types=1);

namespace App\Services\Apolonia;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ApoloniaProductScraper
{
    private const APOLONIA_HOST = 'www.apolonia.com.pl';

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
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public function scrape(string $url, ?array $context = null): array
    {
        $normalizedUrl = $this->normalizeProductUrl($url);

        if ($normalizedUrl === null) {
            return $this->failedResult($url, 'invalid_apolonia_product_url', $context);
        }

        $this->emit('Fetching Apolonia product page: '.$normalizedUrl);
        $this->pauseBeforeRequest();

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeoutSeconds)
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
        $externalProductId = $this->externalProductIdFromUrl($canonicalUrl ?? $sourceUrl);
        $name = $this->productName($crawler);
        $seoTitle = $this->metaContent($crawler, 'meta[property="og:title"], meta[name="twitter:title"]') ?: $this->title($crawler);
        $seoDescription = $this->metaContent($crawler, 'meta[name="description"], meta[property="og:description"], meta[name="twitter:description"]');
        $descriptionHtml = $this->descriptionHtml($crawler, $sourceUrl)
            ?? $this->fallbackDescriptionHtml($crawler, $seoDescription);
        $descriptionPlain = $this->normalizeText(strip_tags($descriptionHtml));
        $productData = $this->productData($html);
        $jsonLdProduct = $this->jsonLdProduct($crawler);
        $attributes = $this->attributes($crawler, strip_tags($html));
        $this->addSelectedVariantAttributes($attributes, $crawler);
        $priceGrossAmount = $this->priceGrossAmount($crawler, $html, $productData, $jsonLdProduct);
        $currency = $this->currency($productData, $jsonLdProduct);
        $availabilityLabel = $this->availabilityLabel($crawler, $productData, $jsonLdProduct);
        $availabilityStatus = $this->availabilityStatus($productData, $jsonLdProduct);
        $categoryPath = $this->categoryPath($crawler, $context);
        $sku = $this->attributeValue($attributes, ['Symbol', 'SKU', 'Kod produktu', 'Kod']);
        $variantCandidates = $this->variantCandidates($crawler, $attributes, $priceGrossAmount, $sku, $productData, $currency);

        return [
            'source' => 'apolonia',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $externalProductId,
            'slug' => $this->slugFromUrl($canonicalUrl ?? $sourceUrl),
            'name' => $name,
            'sku' => $sku,
            'ean' => $this->firstEan($attributes),
            'price_gross_amount' => $priceGrossAmount,
            'currency' => $currency,
            'availability' => $this->availability($availabilityLabel, $availabilityStatus),
            'availability_label' => $availabilityLabel,
            'category' => $categoryPath !== [] ? end($categoryPath) : null,
            'categories' => $categoryPath,
            'source_category_path' => $categoryPath,
            'description_html' => $descriptionHtml,
            'description_plain' => $descriptionPlain,
            'short_description' => $this->shortDescription($seoDescription, $descriptionPlain),
            'seo_title' => $this->cleanSeoTitle($seoTitle, $name),
            'seo_description' => $seoDescription,
            'images' => $this->images($crawler, $sourceUrl),
            'attributes' => $attributes,
            'variant_candidates' => $variantCandidates,
            'raw_context' => $context,
            'warnings' => $name === '' ? ['Product name not found.'] : [],
            'failed_urls' => [],
        ];
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isApoloniaUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        if (preg_match('~^/product-pol-\d+-[^?]+\.html$~u', $path) !== 1) {
            return null;
        }

        return 'https://'.self::APOLONIA_HOST.$path;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function failedResult(string $url, string $reason, ?array $context): array
    {
        return [
            'source' => 'apolonia',
            'source_url' => $url,
            'canonical_url' => null,
            'external_product_id' => null,
            'slug' => null,
            'name' => '',
            'sku' => null,
            'ean' => null,
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'availability' => 'unknown',
            'availability_label' => null,
            'category' => null,
            'categories' => [],
            'source_category_path' => $this->contextCategoryPath($context),
            'description_html' => null,
            'description_plain' => null,
            'short_description' => null,
            'seo_title' => null,
            'seo_description' => null,
            'images' => [],
            'attributes' => [],
            'variant_candidates' => [],
            'raw_context' => $context,
            'warnings' => [],
            'failed_urls' => [$url => $reason],
        ];
    }

    private function canonicalUrl(Crawler $crawler, string $sourceUrl): ?string
    {
        foreach (['link[rel="canonical"][href]', 'meta[property="og:url"][content]'] as $selector) {
            $value = $selector[0] === 'l'
                ? $this->attr($crawler, $selector, 'href')
                : $this->attr($crawler, $selector, 'content');

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
        foreach (['h1[itemprop="name"]', 'h1.product_name__name', '#projector_form h1', 'h1', 'meta[property="og:title"][content]'] as $selector) {
            if (str_starts_with($selector, 'meta')) {
                $value = $this->metaContent($crawler, $selector);
            } else {
                $value = $this->text($crawler, $selector);
            }

            $value = $this->cleanProductTitle($value ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function title(Crawler $crawler): ?string
    {
        return $this->text($crawler, 'title');
    }

    private function cleanProductTitle(string $title): string
    {
        $title = $this->normalizeText($title);
        $title = preg_replace('/\s*\|\s*Sklep.+$/u', '', $title) ?? $title;
        $title = preg_replace('/\s*\|\s*Apolonia.*$/u', '', $title) ?? $title;

        return trim($title);
    }

    private function cleanSeoTitle(?string $title, string $fallback): ?string
    {
        $title = $title !== null ? $this->normalizeText($title) : '';

        return $title !== '' ? $title : ($fallback !== '' ? $fallback : null);
    }

    private function descriptionHtml(Crawler $crawler, string $sourceUrl): ?string
    {
        foreach ([
            '#projector_longdescription',
            '#projector_description',
            '.projector_longdescription',
            '.product_longdescription',
            '.product_description',
            '.product__description',
            '#description',
            '#opis',
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

        foreach (['div[itemprop="description"]', '[data-tab="description"]', '.tabs__content'] as $selector) {
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

        return null;
    }

    private function fallbackDescriptionHtml(Crawler $crawler, ?string $seoDescription): string
    {
        $descriptions = [];

        foreach ([
            '[class~="product_name__block"][class~="--description"] li',
            '[class~="product_name__block"][class~="--description"]',
            '[class~="product_name__description"] li',
            '[class~="product_name__description"]',
        ] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$descriptions): void {
                    $text = $this->nullableText($node->text('', false));

                    if ($text !== null) {
                        $descriptions[] = $text;
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        $metaDescription = $this->nullableText((string) $seoDescription);

        if ($metaDescription !== null) {
            $descriptions[] = $metaDescription;
        }

        $descriptions = array_values(array_unique(array_filter($descriptions, fn (string $text): bool => $text !== '')));

        if ($descriptions === []) {
            return '';
        }

        return implode("\n", array_map(
            fn (string $text): string => '<p>'.e($text).'</p>',
            $descriptions,
        ));
    }

    private function sanitizeHtml(string $html, string $sourceUrl): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#isu', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+srcset\s*=\s*("[^"]*"|\'[^\']*\')/iu', '', $html) ?? $html;
        $html = preg_replace_callback('/\s+(src|href)\s*=\s*(?P<quote>["\'])(?P<url>[^"\']+)\k<quote>/iu', function (array $match) use ($sourceUrl): string {
            $attribute = mb_strtolower((string) ($match[1] ?? ''));
            $url = (string) ($match['url'] ?? '');

            if ($attribute === 'href') {
                return '';
            }

            $absolute = $this->normalizeImageUrl($url, $sourceUrl);

            return $absolute !== null ? ' src="'.e($absolute).'"' : '';
        }, $html) ?? $html;

        return trim($html);
    }


    /**
     * @return array<string, mixed>|null
     */
    private function productData(string $html): ?array
    {
        $array = $this->extractBalancedAssignment($html, 'window.product_data', '[', ']');

        if ($array === null) {
            return null;
        }

        $products = $this->topLevelObjects($array);
        $product = $products[0] ?? null;

        if ($product === null) {
            return null;
        }

        $basePrice = $this->extractBalancedProperty($product, 'base_price', '{', '}');
        $sizesArray = $this->extractBalancedProperty($product, 'sizes', '[', ']');
        $sizes = [];

        if ($sizesArray !== null) {
            foreach ($this->topLevelObjects($sizesArray) as $sizeObject) {
                $name = $this->jsScalarProperty($sizeObject, 'name');

                if ($name === null || ! $this->looksLikeSize($name)) {
                    continue;
                }

                $availability = $this->extractBalancedProperty($sizeObject, 'availability', '{', '}');
                $price = $this->extractBalancedProperty($sizeObject, 'price', '{', '}');
                $amountMw = $this->jsScalarProperty($sizeObject, 'amount_mw');

                $sizes[] = [
                    'id' => $this->jsScalarProperty($sizeObject, 'id'),
                    'name' => $name,
                    'product_id' => $this->jsScalarProperty($sizeObject, 'product_id') ?: $this->jsScalarProperty($product, 'id'),
                    'price_gross_amount' => $this->moneyToMinorUnits($this->jsGrossValue($price ?? '') ?: $this->jsScalarProperty($basePrice ?? '', 'value')),
                    'availability_label' => $availability !== null ? $this->nullableText((string) $this->jsScalarProperty($availability, 'description')) : null,
                    'availability_status' => $availability !== null ? $this->nullableText((string) $this->jsScalarProperty($availability, 'status')) : null,
                    'stock_quantity' => is_numeric($amountMw) && (int) $amountMw >= 0 ? (int) $amountMw : null,
                ];
            }
        }

        return [
            'id' => $this->jsScalarProperty($product, 'id'),
            'currency' => $this->normalizeCurrency($this->jsScalarProperty($product, 'currency')),
            'price_gross_amount' => $this->moneyToMinorUnits($this->jsScalarProperty($basePrice ?? '', 'value')),
            'sizes' => $sizes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonLdProduct(Crawler $crawler): ?array
    {
        $product = null;

        try {
            $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $script) use (&$product): void {
                if ($product !== null) {
                    return;
                }

                $decoded = json_decode($script->text('', false), true);

                if (is_array($decoded)) {
                    $product = $this->findJsonLdProduct($decoded);
                }
            });
        } catch (Throwable) {
            return null;
        }

        if (! is_array($product)) {
            return null;
        }

        $offer = $this->firstJsonLdOffer($product);

        if ($offer === null) {
            return null;
        }

        $availability = is_string($offer['availability'] ?? null) ? $offer['availability'] : null;

        return [
            'price_gross_amount' => $this->moneyToMinorUnits($offer['price'] ?? null),
            'currency' => $this->normalizeCurrency(is_string($offer['priceCurrency'] ?? null) ? $offer['priceCurrency'] : null),
            'availability_status' => $availability !== null ? mb_strtolower($availability) : null,
            'availability_label' => $availability !== null ? basename($availability) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @return array<string, mixed>|null
     */
    private function findJsonLdProduct(array $node): ?array
    {
        $type = $node['@type'] ?? null;

        if ((is_string($type) && mb_strtolower($type) === 'product')
            || (is_array($type) && in_array('Product', $type, true))) {
            /** @var array<string, mixed> $node */
            return $node;
        }

        foreach (['@graph', 'itemListElement'] as $key) {
            if (! is_array($node[$key] ?? null)) {
                continue;
            }

            foreach ($node[$key] as $child) {
                if (is_array($child)) {
                    $result = $this->findJsonLdProduct($child);

                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $result = $this->findJsonLdProduct($child);

                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>|null
     */
    private function firstJsonLdOffer(array $product): ?array
    {
        $offers = $product['offers'] ?? null;

        if (! is_array($offers)) {
            return null;
        }

        if (isset($offers['price']) || isset($offers['priceCurrency'])) {
            /** @var array<string, mixed> $offers */
            return $offers;
        }

        foreach ($offers as $offer) {
            if (is_array($offer) && (isset($offer['price']) || isset($offer['priceCurrency']))) {
                /** @var array<string, mixed> $offer */
                return $offer;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{label?: string, value?: string, code?: string, slug?: string}>  $attributes
     */
    private function addSelectedVariantAttributes(array &$attributes, Crawler $crawler): void
    {
        $indexed = [];

        foreach ($attributes as $attribute) {
            $label = $this->normalizeText((string) ($attribute['label'] ?? ''));
            $value = $this->normalizeText((string) ($attribute['value'] ?? ''));

            if ($label === '' || $value === '') {
                continue;
            }

            $indexed[mb_strtolower((Str::slug($label) ?: $label).'|'.$value)] = [
                'label' => $label,
                'value' => $value,
                'code' => $attribute['code'] ?? (Str::slug($label) ?: substr(sha1($label), 0, 10)),
                'slug' => $attribute['slug'] ?? (Str::slug($value) ?: substr(sha1($value), 0, 10)),
            ];
        }

        $selected = $this->selectedVariantOption($crawler);

        if ($selected !== null) {
            $this->addAttribute($indexed, $selected['label'], $selected['value']);
        }

        $attributes = array_values($indexed);
    }

    /**
     * @return array{label: string, value: string}|null
     */
    private function selectedVariantOption(Crawler $crawler): ?array
    {
        foreach (['#projector_variants_section', '.projector_variants'] as $selector) {
            try {
                $section = $crawler->filter($selector)->first();
            } catch (Throwable) {
                continue;
            }

            if ($section->count() === 0) {
                continue;
            }

            $label = $this->text($section, '.projector_variants__label, label, span') ?? 'Wariant';
            $value = null;

            foreach (['select option[selected]', 'select option'] as $optionSelector) {
                try {
                    $option = $section->filter($optionSelector)->first();
                } catch (Throwable) {
                    continue;
                }

                if ($option->count() > 0) {
                    $value = $this->normalizeText($option->text('', true));
                    break;
                }
            }

            if ($value !== null && $value !== '') {
                return [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $productData
     * @param  array<string, mixed>|null  $jsonLdProduct
     */
    private function currency(?array $productData, ?array $jsonLdProduct): string
    {
        return $this->normalizeCurrency($productData['currency'] ?? null)
            ?? $this->normalizeCurrency($jsonLdProduct['currency'] ?? null)
            ?? 'PLN';
    }

    private function normalizeCurrency(mixed $currency): ?string
    {
        if (! is_string($currency)) {
            return null;
        }

        $currency = mb_strtoupper(trim($currency));

        return match ($currency) {
            'PLN', 'ZŁ', 'ZL' => 'PLN',
            default => $currency !== '' ? $currency : null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $productData
     * @param  array<string, mixed>|null  $jsonLdProduct
     */
    private function availabilityStatus(?array $productData, ?array $jsonLdProduct): ?string
    {
        foreach (['enable', 'order', 'disable'] as $preferredStatus) {
            foreach (($productData['sizes'] ?? []) as $size) {
                if (! is_array($size)) {
                    continue;
                }

                $status = $this->nullableText((string) ($size['availability_status'] ?? ''));

                if ($status !== null && mb_strtolower($status) === $preferredStatus) {
                    return $status;
                }
            }
        }

        return $this->nullableText((string) ($jsonLdProduct['availability_status'] ?? ''));
    }

    /**
     * @param  array<string, mixed>|null  $productData
     */
    private function productDataAvailabilityLabel(?array $productData): ?string
    {
        foreach (['enable', 'order', 'disable'] as $preferredStatus) {
            foreach (($productData['sizes'] ?? []) as $size) {
                if (! is_array($size)) {
                    continue;
                }

                $status = mb_strtolower((string) ($size['availability_status'] ?? ''));
                $label = $this->nullableText((string) ($size['availability_label'] ?? ''));

                if ($label !== null && $status === $preferredStatus) {
                    return $label;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array{label: string, value: string, code: string, slug: string}>
     */
    private function attributes(Crawler $crawler, string $plainHtml): array
    {
        $attributes = [];

        foreach (['table tr', 'dl', '.dictionary__param', '.projector_info__item', '.product_info__item', '.parameters li', '.traits li'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $row) use (&$attributes): void {
                    $cells = $row->filter('th, td, dt, dd, .label, .name, .value, .dictionary__name, .dictionary__value');

                    if ($cells->count() >= 2) {
                        $this->addAttribute($attributes, $cells->eq(0)->text('', true), $cells->eq(1)->text('', true));

                        return;
                    }

                    $text = $this->normalizeText($row->text('', true));
                    $parts = preg_split('/\s*[:–-]\s*/u', $text, 2);

                    if (is_array($parts) && count($parts) === 2) {
                        $this->addAttribute($attributes, $parts[0], $parts[1]);
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        $text = $this->normalizeText($plainHtml);

        foreach (['Marka', 'Symbol', 'Kolor', 'Rękaw', 'Tkanina', 'Kolekcja', 'Odzież', 'Obuwie', 'Kod producenta'] as $label) {
            $value = $this->valueAfterLabelInPlainText($text, $label);

            if ($value !== null) {
                $this->addAttribute($attributes, $label, $value);
            }
        }

        return array_values($attributes);
    }

    /**
     * @param  array<string, array{label: string, value: string, code: string, slug: string}>  $attributes
     */
    private function addAttribute(array &$attributes, string $label, string $value): void
    {
        $label = $this->normalizeText($label);
        $value = $this->normalizeText($value);
        $label = rtrim($label, ':');

        if ($label === '' || $value === '' || mb_strlen($label) > 80 || mb_strlen($value) > 500) {
            return;
        }

        if ($this->isIgnoredAttributeLabel($label)) {
            return;
        }

        $code = Str::slug($label) ?: substr(sha1($label), 0, 10);
        $dedupeKey = mb_strtolower($code.'|'.$value);

        if (isset($attributes[$dedupeKey])) {
            return;
        }

        $attributes[$dedupeKey] = [
            'label' => $label,
            'value' => $value,
            'code' => $code,
            'slug' => Str::slug($value) ?: substr(sha1($value), 0, 10),
        ];
    }

    private function isIgnoredAttributeLabel(string $label): bool
    {
        $normalized = Str::slug($label);

        return in_array($normalized, [
            'wysylka',
            'dostawa',
            'dostepnosc',
            'cena',
            'cena-regularna',
            'cena-sugerowana',
            'mozliwe-jest-rowniez-zamowienie-odziezy-w-innej-kolorystyce-i-w-innych-tkaninach-niz-te-dostepne-w-sklepie-internetowym-jednak-bez-mozliwosci-zwrotu-ani-wymiany',
        ], true);
    }

    private function valueAfterLabelInPlainText(string $text, string $label): ?string
    {
        $quoted = preg_quote($label, '/');

        if (preg_match('/(?:^|\s)'.$quoted.'\s+(?P<value>.{1,180}?)(?:\s+(?:Marka|Symbol|Kod producenta|Kolor|Rękaw|Tkanina|Kolekcja|Odzież|Obuwie|Cena na telefon|Potrzebujesz pomocy|Zadaj pytanie)\b|$)/u', $text, $matches) !== 1) {
            return null;
        }

        $value = $this->normalizeText((string) $matches['value']);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $productData
     * @param  array<string, mixed>|null  $jsonLdProduct
     */
    private function priceGrossAmount(Crawler $crawler, string $html, ?array $productData = null, ?array $jsonLdProduct = null): ?int
    {
        $productDataPrice = $this->moneyToMinorUnits($productData['price_gross_amount'] ?? null);

        if ($productDataPrice !== null) {
            return $productDataPrice;
        }

        $jsonLdPrice = $this->moneyToMinorUnits($jsonLdProduct['price_gross_amount'] ?? null);

        if ($jsonLdPrice !== null) {
            return $jsonLdPrice;
        }

        foreach (['meta[property="product:price:amount"][content]', 'meta[itemprop="price"][content]'] as $selector) {
            $value = $this->attr($crawler, $selector, 'content');

            if ($value !== null) {
                $amount = $this->moneyToMinorUnits($value);

                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        foreach (['#projector_price_value', '.projector_prices__price', '.price.--main', '.price', '[itemprop="price"]'] as $selector) {
            $value = $this->text($crawler, $selector);

            if ($value !== null) {
                $amount = $this->moneyToMinorUnits($value);

                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        if (preg_match('/(?P<amount>\d{1,4}(?:[\s.]\d{3})*,\d{2}|\d{1,6}\.\d{2})\s*zł/u', strip_tags($html), $matches) === 1) {
            return $this->moneyToMinorUnits((string) $matches['amount']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $productData
     * @param  array<string, mixed>|null  $jsonLdProduct
     */
    private function availabilityLabel(Crawler $crawler, ?array $productData = null, ?array $jsonLdProduct = null): ?string
    {
        foreach (['.projector_status__description', '.projector_status', '.product_availability', '.availability', '[itemprop="availability"]'] as $selector) {
            $value = $this->text($crawler, $selector);

            if ($value !== null && $this->normalizeText($value) !== '') {
                return $this->normalizeText($value);
            }
        }

        $productDataLabel = $this->productDataAvailabilityLabel($productData);

        if ($productDataLabel !== null) {
            return $productDataLabel;
        }

        $jsonLdLabel = $this->nullableText((string) ($jsonLdProduct['availability_label'] ?? ''));

        if ($jsonLdLabel !== null) {
            return $jsonLdLabel;
        }

        $text = $this->normalizeText($crawler->text('', true));

        if (preg_match('/Produkt dostępny[^.\n]*/u', $text, $matches) === 1) {
            return $this->normalizeText($matches[0]);
        }

        return null;
    }

    private function availability(?string $label, ?string $status = null): string
    {
        $status = mb_strtolower((string) $status);
        $label = mb_strtolower((string) $label);

        if (in_array($status, ['enable', 'instock', 'in_stock', 'https://schema.org/instock'], true)) {
            return 'in_stock';
        }

        if (in_array($status, ['order', 'preorder', 'pre_order', 'available_on_backorder', 'https://schema.org/preorder'], true)) {
            return 'preorder';
        }

        if (in_array($status, ['disable', 'disabled', 'outofstock', 'out_of_stock', 'https://schema.org/outofstock'], true)) {
            return 'out_of_stock';
        }

        if ($label === '') {
            return 'unknown';
        }

        if (str_contains($label, 'niedostęp') || str_contains($label, 'niedostep') || str_contains($label, 'brak')) {
            return 'out_of_stock';
        }

        if (str_contains($label, 'na zamówienie') || str_contains($label, 'na zamowienie')) {
            return 'preorder';
        }

        if (str_contains($label, 'dostęp') || str_contains($label, 'dostep')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<int, string>
     */
    private function categoryPath(Crawler $crawler, ?array $context): array
    {
        $breadcrumbs = [];

        foreach (['#breadcrumbs ol > li > a, #breadcrumbs ol > li > span', '.breadcrumbs ol > li > a, .breadcrumbs ol > li > span', '.breadcrumbs > a, .breadcrumbs > span', '#breadcrumbs > a, #breadcrumbs > span', '.breadcrumb a, .breadcrumb span'] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            if ($nodes->count() === 0) {
                continue;
            }

            $nodes->each(function (Crawler $node) use (&$breadcrumbs): void {
                $text = $this->normalizeText($node->text('', true));

                if ($text === '' || in_array($text, ['Strona główna', 'Wstecz'], true)) {
                    return;
                }

                if (! in_array($text, $breadcrumbs, true)) {
                    $breadcrumbs[] = $text;
                }
            });

            if ($breadcrumbs !== []) {
                break;
            }
        }

        if ($breadcrumbs !== []) {
            array_pop($breadcrumbs); // product name

            return array_values($breadcrumbs);
        }

        return $this->contextCategoryPath($context);
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<int, string>
     */
    private function contextCategoryPath(?array $context): array
    {
        if (is_array($context['category_path'] ?? null)) {
            return array_values(array_filter(array_map(
                fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
                $context['category_path'],
            )));
        }

        if (is_string($context['category_name'] ?? null) && trim($context['category_name']) !== '') {
            return [trim($context['category_name'])];
        }

        return [];
    }

    /**
     * @return array<int, array{url: string, alt: string|null, title: string|null, role: string|null}>
     */
    private function images(Crawler $crawler, string $baseUrl): array
    {
        $images = [];

        foreach (['#projector_photos figure, #projector_photos picture, #projector_photos img', '.projector_photos figure, .projector_photos picture, .projector_photos img', '.photos figure, .photos picture, .photos img', '.product_photos figure, .product_photos picture, .product_photos img', 'main figure, main picture, main img', '#content figure, #content picture, #content img'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $baseUrl): void {
                    if ($this->isNestedGalleryImageNode($node)) {
                        return;
                    }

                    $url = $this->bestImageUrl($node, $baseUrl);

                    if ($url === null || isset($images[$url])) {
                        return;
                    }

                    $images[$url] = [
                        'url' => $url,
                        'alt' => $this->imageNodeAttribute($node, 'alt'),
                        'title' => $this->imageNodeAttribute($node, 'title'),
                        'role' => 'gallery',
                    ];
                });
            } catch (Throwable) {
                continue;
            }

            if ($images !== []) {
                break;
            }
        }

        foreach (['meta[property="og:image"][content]', 'meta[name="twitter:image"][content]'] as $selector) {
            $url = $this->attr($crawler, $selector, 'content');
            $normalizedUrl = is_string($url) ? $this->normalizeImageUrl($url, $baseUrl) : null;

            if ($normalizedUrl !== null && ! $this->isIgnoredImageUrl($normalizedUrl)) {
                $images[$normalizedUrl] ??= [
                    'url' => $normalizedUrl,
                    'alt' => null,
                    'title' => null,
                    'role' => 'gallery',
                ];
            }
        }

        return array_values($images);
    }

    private function isNestedGalleryImageNode(Crawler $node): bool
    {
        $domNode = $node->getNode(0);

        if (! $domNode instanceof \DOMElement || mb_strtolower($domNode->tagName) !== 'img') {
            return false;
        }

        $parent = $domNode->parentNode;

        while ($parent instanceof \DOMElement) {
            $tagName = mb_strtolower($parent->tagName);

            if (in_array($tagName, ['picture', 'figure'], true)) {
                return true;
            }

            if (in_array($tagName, ['section', 'main', 'div'], true)) {
                return false;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function bestImageUrl(Crawler $node, string $baseUrl): ?string
    {
        $candidates = [];

        foreach (['source', 'img'] as $selector) {
            try {
                $node->filter($selector)->each(function (Crawler $child) use (&$candidates): void {
                    foreach (['data-img_high_res_webp', 'data-img_high_res', 'data-large', 'data-original', 'data-src', 'srcset', 'src'] as $attribute) {
                        $value = $child->attr($attribute);

                        if (is_string($value) && trim($value) !== '') {
                            $candidates[] = $value;
                        }
                    }
                });
            } catch (Throwable) {
                // The current node may itself be an img/source node.
            }
        }

        foreach (['data-img_high_res_webp', 'data-img_high_res', 'data-large', 'data-original', 'data-src', 'srcset', 'src'] as $attribute) {
            $value = $node->attr($attribute);

            if (is_string($value) && trim($value) !== '') {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            $url = $this->normalizeImageUrl($this->firstSrcSetCandidate($candidate), $baseUrl);

            if ($url !== null && ! $this->isIgnoredImageUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    private function imageNodeAttribute(Crawler $node, string $attribute): ?string
    {
        $value = $node->attr($attribute);

        if (is_string($value) && trim($value) !== '') {
            return $this->nullableText($value);
        }

        try {
            $value = $node->filter('img')->first()->attr($attribute);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $this->nullableText($value) : null;
    }

    private function normalizeImageUrl(string $url, string $baseUrl): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isApoloniaUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));
        $query = (string) parse_url($absolute, PHP_URL_QUERY);

        if (! preg_match('/\.(?:jpe?g|png|webp|gif)(?:$|\?)/iu', $path)) {
            // IdoSell can expose extensionless resized image URLs under /data/gfx.
            if (! str_contains($path, '/data/') && ! str_contains($path, '/gfx/')) {
                return null;
            }
        }

        return 'https://'.self::APOLONIA_HOST.$path.($query !== '' ? '?'.$query : '');
    }

    private function isIgnoredImageUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, 'logo')
            || str_contains($path, 'favicon')
            || str_contains($path, 'poweredby')
            || str_contains($path, 'paypo')
            || str_contains($path, 'safe_light')
            || str_contains($path, 'apple.svg');
    }


    private function firstSrcSetCandidate(string $src): string
    {
        $src = trim($src);
        $first = trim(explode(',', $src)[0] ?? $src);
        $parts = preg_split('/\s+/u', $first);

        return is_array($parts) && isset($parts[0]) ? (string) $parts[0] : $src;
    }

    /**
     * @param  array<string, mixed>|null  $productData
     * @return array<int, array<string, mixed>>
     */
    private function variantCandidatesFromProductData(Crawler $crawler, ?array $productData, ?int $fallbackPriceGrossAmount, ?string $baseSku, string $currency): array
    {
        if (! is_array($productData) || ! is_array($productData['sizes'] ?? null)) {
            return [];
        }

        $selectedVariantOption = $this->selectedVariantOption($crawler);
        $variants = [];
        $index = 0;

        foreach ($productData['sizes'] as $size) {
            if (! is_array($size)) {
                continue;
            }

            $sizeName = $this->nullableText((string) ($size['name'] ?? ''));

            if ($sizeName === null || ! $this->looksLikeSize($sizeName)) {
                continue;
            }

            $index++;
            $productId = $this->nullableText((string) ($size['product_id'] ?? $productData['id'] ?? ''));
            $sizeId = $this->nullableText((string) ($size['id'] ?? ''));
            $externalVariantId = implode('-', array_filter([$productId, $sizeId ?: (string) $index]));
            $availabilityLabel = $this->nullableText((string) ($size['availability_label'] ?? ''));
            $availabilityStatus = $this->nullableText((string) ($size['availability_status'] ?? ''));
            $priceGrossAmount = $this->moneyToMinorUnits($size['price_gross_amount'] ?? null) ?? $fallbackPriceGrossAmount;
            $attributes = [];

            if ($selectedVariantOption !== null) {
                $attributes[] = [
                    'code' => Str::slug($selectedVariantOption['label']) ?: substr(sha1($selectedVariantOption['label']), 0, 10),
                    'label' => $selectedVariantOption['label'],
                    'value' => $selectedVariantOption['value'],
                    'slug' => Str::slug($selectedVariantOption['value']) ?: substr(sha1($selectedVariantOption['value']), 0, 10),
                ];
            }

            $attributes[] = [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => $sizeName,
                'slug' => Str::slug($sizeName) ?: mb_strtolower($sizeName),
            ];

            $variants[] = [
                'external_variant_id' => $externalVariantId !== '' ? $externalVariantId : (string) $index,
                'name' => $sizeName,
                'label' => $sizeName,
                'sku' => $baseSku !== null ? $baseSku.'-'.$sizeName : null,
                'price_gross_amount' => $priceGrossAmount,
                'currency' => $currency,
                'availability' => $this->availability($availabilityLabel, $availabilityStatus),
                'availability_label' => $availabilityLabel,
                'source_availability_status' => $availabilityStatus,
                'stock_quantity' => is_int($size['stock_quantity'] ?? null) ? $size['stock_quantity'] : null,
                'attributes' => $attributes,
            ];
        }

        return $variants;
    }

    private function extractBalancedAssignment(string $source, string $assignment, string $open, string $close): ?string
    {
        $assignmentPosition = strpos($source, $assignment);

        if ($assignmentPosition === false) {
            return null;
        }

        $openPosition = strpos($source, $open, $assignmentPosition + strlen($assignment));

        if ($openPosition === false) {
            return null;
        }

        return $this->extractBalancedFromPosition($source, $openPosition, $open, $close);
    }

    private function extractBalancedProperty(string $source, string $property, string $open, string $close): ?string
    {
        if (preg_match('/\b'.preg_quote($property, '/').'\s*:/u', $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $openPosition = strpos($source, $open, (int) $matches[0][1] + strlen((string) $matches[0][0]));

        if ($openPosition === false) {
            return null;
        }

        return $this->extractBalancedFromPosition($source, $openPosition, $open, $close);
    }

    private function extractBalancedFromPosition(string $source, int $openPosition, string $open, string $close): ?string
    {
        $length = strlen($source);
        $depth = 0;
        $quote = null;
        $escaped = false;

        for ($index = $openPosition; $index < $length; $index++) {
            $char = $source[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === $open) {
                $depth++;
            }

            if ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $openPosition, $index - $openPosition + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function topLevelObjects(string $arraySource): array
    {
        $objects = [];
        $length = strlen($arraySource);
        $quote = null;
        $escaped = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $arraySource[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char !== '{') {
                continue;
            }

            $object = $this->extractBalancedFromPosition($arraySource, $index, '{', '}');

            if ($object === null) {
                continue;
            }

            $objects[] = $object;
            $index += strlen($object) - 1;
        }

        return $objects;
    }

    private function jsScalarProperty(string $source, string $property): ?string
    {
        if ($source === '') {
            return null;
        }

        if (preg_match('/\b'.preg_quote($property, '/').'\s*:\s*(?:"(?P<double>(?:\\\\.|[^"])*)"|\'(?P<single>(?:\\\\.|[^\'])*)\'|(?P<bare>-?\d+(?:\.\d+)?|true|false|null))/u', $source, $matches) !== 1) {
            return null;
        }

        $value = $matches['double'] !== ''
            ? $matches['double']
            : ($matches['single'] !== '' ? $matches['single'] : ($matches['bare'] ?? ''));

        if ($value === '' || $value === 'null') {
            return null;
        }

        $value = str_replace(['\\/', '\\"', "\\'"], ['/', '"', "'"], (string) $value);

        return $this->normalizeText($value);
    }

    private function jsGrossValue(string $source): ?string
    {
        if ($source === '') {
            return null;
        }

        if (preg_match('/gross\s*:\s*\{.*?value\s*:\s*(?:"(?P<double>\d+(?:\.\d+)?)"|\'(?P<single>\d+(?:\.\d+)?)\'|(?P<bare>\d+(?:\.\d+)?))/su', $source, $matches) !== 1) {
            return null;
        }

        return $matches['double'] !== ''
            ? $matches['double']
            : ($matches['single'] !== '' ? $matches['single'] : ($matches['bare'] ?? null));
    }

    /**
     * @param  array<int, array{label: string, value: string, code: string, slug: string}>  $attributes
     * @param  array<string, mixed>|null  $productData
     * @return array<int, array<string, mixed>>
     */
    private function variantCandidates(Crawler $crawler, array $attributes, ?int $priceGrossAmount, ?string $baseSku, ?array $productData = null, string $currency = 'PLN'): array
    {
        $productDataVariants = $this->variantCandidatesFromProductData($crawler, $productData, $priceGrossAmount, $baseSku, $currency);

        if ($productDataVariants !== []) {
            return $productDataVariants;
        }

        $sizes = [];

        foreach (['#projector_sizes_cont a', '#projector_sizes_cont button', '.projector_sizes a', '.projector_sizes button', '.sizes a', '.sizes button', 'a[data-type="size"]', 'button[data-type="size"]'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$sizes): void {
                    $label = $this->normalizeText($node->text('', true));
                    $label = preg_replace('/\s+/', '', $label) ?? $label;

                    if ($this->looksLikeSize($label)) {
                        $sizes[$label] = $label;
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        if ($sizes === []) {
            foreach ($attributes as $attribute) {
                if (Str::slug((string) ($attribute['label'] ?? '')) === 'rozmiar') {
                    foreach (preg_split('/\s*,\s*|\s+/u', (string) ($attribute['value'] ?? '')) ?: [] as $size) {
                        $size = $this->normalizeText($size);

                        if ($this->looksLikeSize($size)) {
                            $sizes[$size] = $size;
                        }
                    }
                }
            }
        }

        if ($sizes === []) {
            return [];
        }

        $variants = [];
        $index = 0;

        foreach (array_values($sizes) as $size) {
            $index++;
            $variants[] = [
                'external_variant_id' => (string) $index,
                'name' => $size,
                'label' => $size,
                'sku' => $baseSku !== null ? $baseSku.'-'.$size : null,
                'price_gross_amount' => $priceGrossAmount,
                'currency' => $currency,
                'availability' => 'unknown',
                'attributes' => [[
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => $size,
                    'slug' => Str::slug($size) ?: mb_strtolower($size),
                ]],
            ];
        }

        return $variants;
    }

    private function looksLikeSize(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 12) {
            return false;
        }

        return preg_match('/^(?:XXS|XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL|6XL|\d{2}(?:\/\d{2})?|\d{2}-\d{2}|Uniwersalny)$/iu', $value) === 1;
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $attributes
     * @param  array<int, string>  $labels
     */
    private function attributeValue(array $attributes, array $labels): ?string
    {
        $wanted = array_map(static fn (string $label): string => Str::slug($label), $labels);

        foreach ($attributes as $attribute) {
            if (in_array(Str::slug((string) ($attribute['label'] ?? '')), $wanted, true)) {
                $value = $this->nullableText((string) ($attribute['value'] ?? ''));

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $attributes
     */
    private function firstEan(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            $value = (string) ($attribute['value'] ?? '');

            if (preg_match('/\b(\d{8}|\d{12,14})\b/', $value, $matches) === 1) {
                return (string) $matches[1];
            }
        }

        return null;
    }

    private function shortDescription(?string $seoDescription, ?string $descriptionPlain): ?string
    {
        $text = $this->nullableText((string) $seoDescription) ?? $this->nullableText((string) $descriptionPlain);

        if ($text === null) {
            return null;
        }

        return Str::limit($text, 300, '');
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[^0-9,.]/', '', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    private function externalProductIdFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('/product-pol-(\d+)-/u', $path, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    private function slugFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if (preg_match('/^product-pol-\d+-(?P<slug>.+)\.html$/u', $path, $matches) === 1) {
            return Str::slug((string) $matches['slug']) ?: null;
        }

        return null;
    }

    private function normalizeAbsoluteUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = str_replace(['\\/', '\/'], '/', $url);

        if ($url === ''
            || str_starts_with($url, '#')
            || str_starts_with(mb_strtolower($url), 'mailto:')
            || str_starts_with(mb_strtolower($url), 'tel:')
            || str_starts_with(mb_strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::APOLONIA_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            $baseUrl ??= ApoloniaCategoryUrlScraper::DEFAULT_URL;
            $baseParts = parse_url($baseUrl);
            $baseHost = is_string($baseParts['host'] ?? null) ? $this->normalizeHost((string) $baseParts['host']) : self::APOLONIA_HOST;
            $basePath = is_string($baseParts['path'] ?? null) ? dirname((string) $baseParts['path']) : '/';
            $basePath = $basePath === '.' ? '/' : $basePath;
            $url = 'https://'.($baseHost ?? self::APOLONIA_HOST).'/'.ltrim(trim($basePath, '/').'/'.$url, '/');
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.$host.$path.$query;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function isApoloniaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::APOLONIA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            self::APOLONIA_HOST, 'apolonia.com.pl' => self::APOLONIA_HOST,
            default => null,
        };
    }

    private function metaContent(Crawler $crawler, string $selector): ?string
    {
        return $this->attr($crawler, $selector, 'content');
    }

    private function attr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
        } catch (Throwable) {
            return null;
        }

        if ($node->count() === 0) {
            return null;
        }

        $value = $node->attr($attribute);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function text(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
        } catch (Throwable) {
            return null;
        }

        if ($node->count() === 0) {
            return null;
        }

        $text = $this->normalizeText($node->text('', true));

        return $text !== '' ? $text : null;
    }

    private function nullableText(string $text): ?string
    {
        $text = $this->normalizeText($text);

        return $text === '' ? null : $text;
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopApoloniaProductScraper/1.0; +https://konji.pl)',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
