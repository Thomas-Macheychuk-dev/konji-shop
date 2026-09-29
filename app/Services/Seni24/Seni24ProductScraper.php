<?php

declare(strict_types=1);

namespace App\Services\Seni24;

use Closure;
use DOMElement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class Seni24ProductScraper
{
    private const HOST = 'seni24.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 500;

    private int $maxVariantCombinations = 250;

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

    public function withMaxVariantCombinations(int $combinations): self
    {
        $this->maxVariantCombinations = max(1, $combinations);

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
            return $this->failedResult($url, 'invalid_seni24_product_url', $context);
        }

        $this->emit('Fetching Seni24 product page: '.$normalizedUrl);
        $response = $this->get($normalizedUrl);

        if (! $response instanceof Response) {
            return $this->failedResult($normalizedUrl, 'request_failed', $context);
        }

        if (! $response->successful()) {
            return $this->failedResult($normalizedUrl, 'HTTP '.$response->status(), $context);
        }

        $html = $response->body();
        $result = $this->extract($html, $normalizedUrl, $context);

        if (($result['name'] ?? '') === '') {
            return $result;
        }

        try {
            $crawler = new Crawler($html, $normalizedUrl);
        } catch (Throwable) {
            $result['warnings'][] = 'Unable to parse Seni24 variant form.';
            $result['variant_resolution_complete'] = false;

            return $result;
        }

        $groups = $this->variantGroups($crawler);

        if ($groups === []) {
            $result['variant_resolution_complete'] = $this->candidateIsCommerceComplete(
                $result['variant_candidates'][0] ?? null,
            );

            return $result;
        }

        [$variants, $warnings, $complete] = $this->resolveLiveVariants(
            $result['canonical_url'] ?? $normalizedUrl,
            $groups,
            is_array($result['variant_candidates'][0] ?? null)
                ? $result['variant_candidates'][0]
                : null,
            $this->vatRateValue($result['vat_rate'] ?? null),
            (string) ($result['availability'] ?? 'unknown'),
        );

        $result['variant_candidates'] = $variants;
        $result['variant_resolution_complete'] = $complete;
        $result['warnings'] = array_values(array_unique(array_merge(
            is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            $warnings,
        )));

        if ($variants !== []) {
            $grossPrices = array_values(array_filter(array_map(
                fn (array $variant): ?float => $this->decimalAmount($variant['price_gross_amount'] ?? null),
                $variants,
            ), fn (?float $value): bool => $value !== null));

            if ($grossPrices !== []) {
                $result['price_gross_amount'] = min($grossPrices);
            }
        }

        return $result;
    }

    /**
     * Deterministic parser used by tests and by the live scraper before
     * resolving PrestaShop combination refresh requests.
     *
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
        [$externalProductId, $defaultVariantId] = $this->idsFromUrl($identityUrl);
        $name = $this->productName($crawler);
        $jsonLd = $this->jsonLdProduct($crawler);
        [$price, $vatRate] = $this->priceAndVat($crawler, $jsonLd);
        $features = $this->features($crawler);
        $descriptionHtml = $this->descriptionHtml($crawler);
        $descriptionPlain = $this->text(strip_tags($descriptionHtml ?? ''));
        $categoryPath = $this->categoryPath($crawler, $name);
        $availability = $this->availability($crawler);
        $brand = $this->feature($features, 'Marka') ?? $this->jsonLdBrand($jsonLd);
        $supplierSku = $this->feature($features, 'Indeks') ?? $this->jsonLdSku($jsonLd);
        $ean13 = $this->feature($features, 'ean13')
            ?? $this->feature($features, 'EAN')
            ?? $this->jsonLdGtin($jsonLd);
        $isMedicalDevice = $this->yesValue($this->feature($features, 'Wyrób medyczny'))
            || $this->containsComparable($descriptionPlain.' '.$crawler->text(''), 'wyrób medyczny');
        $medicalClass = $this->feature($features, 'Klasa wyrobu medycznego');
        $warnings = [];

        if ($name === '') {
            $warnings[] = 'Product name not found.';
        }

        if ($externalProductId === null) {
            $warnings[] = 'Stable Seni24 product ID not found in canonical URL.';
        }

        if ($price === null) {
            $warnings[] = 'Gross price not found on product page.';
        }

        if ($vatRate === null) {
            $warnings[] = 'Explicit VAT rate not found on product page.';
        }

        if ($defaultVariantId === null) {
            $warnings[] = 'Stable Seni24 default variant ID not found in canonical URL.';
        }

        $defaultAttributes = $this->selectedVariantAttributes($this->variantGroups($crawler));

        if ($supplierSku !== null) {
            $defaultAttributes[] = ['label' => 'Indeks', 'value' => $supplierSku];
        }

        if ($ean13 !== null) {
            $defaultAttributes[] = ['label' => 'EAN', 'value' => $ean13];
        }

        $defaultCandidate = [
            'external_variant_id' => $defaultVariantId,
            'label' => $this->variantLabel($defaultAttributes),
            'attributes' => $defaultAttributes,
            'supplier_sku' => $supplierSku,
            'ean13' => $ean13,
            'price_gross_amount' => $price,
            'currency' => 'PLN',
            'vat_rate' => $vatRate,
            'availability' => $availability,
        ];

        return [
            'source' => 'seni24',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl ?? $sourceUrl,
            'external_product_id' => $externalProductId,
            'slug' => $this->slugFromUrl($identityUrl),
            'name' => $name,
            'brand' => $brand,
            'price_gross_amount' => $price,
            'currency' => 'PLN',
            'vat_rate' => $vatRate,
            'availability' => $availability,
            'availability_label' => $this->availabilityLabel($crawler),
            'source_category_path' => $categoryPath,
            'categories' => $categoryPath,
            'description_html' => $descriptionHtml,
            'description_plain' => $descriptionPlain,
            'seo_title' => $this->metaContent($crawler, 'meta[name="title"]')
                ?? $this->titleText($crawler),
            'seo_description' => $this->metaContent($crawler, 'meta[name="description"]'),
            'images' => $this->images($crawler, $identityUrl, $name),
            'attributes' => $this->attributeRows($features, $brand),
            'variant_candidates' => [$defaultCandidate],
            'variant_resolution_complete' => false,
            'is_medical_device' => $isMedicalDevice,
            'medical_device_class' => $medicalClass,
            'is_refundable' => $this->yesValue($this->feature($features, 'Refundowany')),
            'raw_context' => $context,
            'warnings' => $warnings,
            'failed_urls' => [],
        ];
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://www.'.self::HOST.$url;
        } elseif (! preg_match('#^https?://#iu', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $base = parse_url($baseUrl);

            if (! is_array($base)) {
                return null;
            }

            $url = 'https://www.'.self::HOST.'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host !== self::HOST) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/');

        if (preg_match('/_[0-9]+-[0-9]+$/', $path) !== 1) {
            return null;
        }

        return 'https://www.'.self::HOST.$path;
    }

    /**
     * @param  list<array{id: string, label: string, choices: list<array{id: string, label: string, selected: bool>>}>  $groups
     * @param  array<string, mixed>|null  $defaultCandidate
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: bool}
     */
    private function resolveLiveVariants(
        string $url,
        array $groups,
        ?array $defaultCandidate,
        ?int $productVatRate,
        string $productAvailability,
    ): array {
        $warnings = [];
        $complete = true;
        $combinationCount = 1;

        foreach ($groups as $group) {
            $combinationCount *= max(1, count($group['choices']));

            if ($combinationCount > $this->maxVariantCombinations) {
                $warnings[] = sprintf(
                    'Variant matrix has %d+ combinations and exceeds the safety limit of %d.',
                    $combinationCount,
                    $this->maxVariantCombinations,
                );

                return [
                    $defaultCandidate !== null ? [$defaultCandidate] : [],
                    $warnings,
                    false,
                ];
            }
        }

        $requestedCombinations = $this->cartesianCombinations($groups);
        $variants = [];
        $signaturesById = [];

        if ($defaultCandidate !== null
            && $this->stringOrNull($defaultCandidate['external_variant_id'] ?? null) !== null) {
            $defaultId = (string) $defaultCandidate['external_variant_id'];
            $variants[$defaultId] = $defaultCandidate;
            $signaturesById[$defaultId] = $this->attributeSignature(
                is_array($defaultCandidate['attributes'] ?? null)
                    ? $defaultCandidate['attributes']
                    : [],
            );
        }

        foreach ($requestedCombinations as $index => $combination) {
            $this->emit(sprintf(
                'Resolving Seni24 variant %d/%d for %s',
                $index + 1,
                count($requestedCombinations),
                $url,
            ));

            $parameters = [
                'ajax' => '1',
                'action' => 'refresh',
                'quantity_wanted' => '1',
            ];

            foreach ($combination as $choice) {
                $parameters['group['.$choice['group_id'].']'] = $choice['choice_id'];
            }

            $response = $this->postRefresh($url, $parameters);

            if (! $response instanceof Response || ! $response->successful()) {
                $warnings[] = 'Variant refresh request failed for '.$this->requestedLabel($combination).'.';
                $complete = false;
                continue;
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                $warnings[] = 'Variant refresh response was not JSON for '.$this->requestedLabel($combination).'.';
                $complete = false;
                continue;
            }

            $variantUrl = $this->normalizeProductUrl(
                is_string($payload['product_url'] ?? null)
                    ? $payload['product_url']
                    : $url,
            ) ?? $url;

            [, $variantIdFromUrl] = $this->idsFromUrl($variantUrl);
            $variantId = $this->stringOrNull($payload['id_product_attribute'] ?? null)
                ?? $variantIdFromUrl;

            if ($variantId === null) {
                $warnings[] = 'Variant refresh did not expose a stable combination ID for '.$this->requestedLabel($combination).'.';
                $complete = false;
                continue;
            }

            $priceHtml = '';

            foreach (['product_prices', 'product_price', 'product_add_to_cart', 'product_details'] as $key) {
                if (is_string($payload[$key] ?? null)) {
                    $priceHtml .= ' '.$payload[$key];
                }
            }

            [$price, $variantVat] = $this->priceAndVatFromFragment($priceHtml);

            if ($variantVat === null) {
                $variantVat = $productVatRate;
            }

            $variantHtml = is_string($payload['product_variants'] ?? null)
                ? $payload['product_variants']
                : '';

            $actualGroups = $variantHtml !== ''
                ? $this->variantGroups(new Crawler('<div>'.$variantHtml.'</div>', $url))
                : [];
            $attributes = $actualGroups !== []
                ? $this->selectedVariantAttributes($actualGroups)
                : array_map(
                    fn (array $choice): array => [
                        'label' => $choice['group_label'],
                        'value' => $choice['choice_label'],
                    ],
                    $combination,
                );

            $details = is_string($payload['product_details'] ?? null)
                ? $this->features(new Crawler('<div>'.$payload['product_details'].'</div>', $url))
                : [];
            $supplierSku = $this->feature($details, 'Indeks');
            $ean13 = $this->feature($details, 'ean13') ?? $this->feature($details, 'EAN');

            if ($supplierSku !== null) {
                $attributes[] = ['label' => 'Indeks', 'value' => $supplierSku];
            }

            if ($ean13 !== null) {
                $attributes[] = ['label' => 'EAN', 'value' => $ean13];
            }

            $availabilityHtml = is_string($payload['product_add_to_cart'] ?? null)
                ? $payload['product_add_to_cart']
                : '';
            $availability = $availabilityHtml !== ''
                ? $this->availabilityFromText($this->text(strip_tags($availabilityHtml)))
                : $productAvailability;

            $candidate = [
                'external_variant_id' => $variantId,
                'label' => $this->variantLabel($attributes),
                'attributes' => $attributes,
                'supplier_sku' => $supplierSku,
                'ean13' => $ean13,
                'price_gross_amount' => $price,
                'currency' => 'PLN',
                'vat_rate' => $variantVat,
                'availability' => $availability,
                'source_url' => $variantUrl,
            ];

            if (! $this->candidateIsCommerceComplete($candidate)) {
                $warnings[] = 'Variant '.$variantId.' is missing an explicit gross price or VAT.';
                $complete = false;
            }

            $signature = $this->attributeSignature($attributes);

            if (isset($variants[$variantId])) {
                if (($signaturesById[$variantId] ?? '') !== ''
                    && $signature !== ''
                    && $signaturesById[$variantId] !== $signature) {
                    $warnings[] = 'Variant '.$variantId.' resolved from more than one attribute combination.';
                    $complete = false;
                }

                if (! $this->candidateIsCommerceComplete($variants[$variantId])
                    && $this->candidateIsCommerceComplete($candidate)) {
                    $variants[$variantId] = $candidate;
                    $signaturesById[$variantId] = $signature;
                }

                continue;
            }

            $variants[$variantId] = $candidate;
            $signaturesById[$variantId] = $signature;
        }

        $variants = array_values($variants);

        if ($variants === []) {
            $complete = false;
        }

        return [$variants, array_values(array_unique($warnings)), $complete];
    }

    /**
     * @return list<array{id: string, label: string, choices: list<array{id: string, label: string, selected: bool>>}>
     */
    private function variantGroups(Crawler $crawler): array
    {
        $groups = [];

        try {
            $containers = $crawler->filter('.product-variants .product-variants-item, .product-variants-item');
        } catch (Throwable) {
            return [];
        }

        $containers->each(function (Crawler $container) use (&$groups): void {
            $label = '';

            foreach (['.control-label', 'label.control-label', '.form-control-label'] as $selector) {
                try {
                    $node = $container->filter($selector)->first();

                    if ($node->count() > 0) {
                        $label = $this->text($node->text(''));

                        if ($label !== '') {
                            break;
                        }
                    }
                } catch (Throwable) {
                    // Try next selector.
                }
            }

            $choices = [];
            $groupId = null;

            try {
                $inputs = $container->filter('input[data-product-attribute], input[name^="group["]');

                $inputs->each(function (Crawler $input) use (&$choices, &$groupId): void {
                    $candidateGroupId = $this->groupId(
                        $input->attr('data-product-attribute'),
                        $input->attr('name'),
                    );
                    $choiceId = $this->stringOrNull($input->attr('value'));

                    if ($candidateGroupId === null || $choiceId === null) {
                        return;
                    }

                    $groupId ??= $candidateGroupId;
                    $choiceLabel = $this->choiceLabel($input);

                    if ($choiceLabel === '') {
                        $choiceLabel = $choiceId;
                    }

                    $choices[$choiceId] = [
                        'id' => $choiceId,
                        'label' => $choiceLabel,
                        'selected' => $input->attr('checked') !== null,
                    ];
                });
            } catch (Throwable) {
                // Select parsing below may still work.
            }

            try {
                $selects = $container->filter('select[data-product-attribute], select[name^="group["]');

                $selects->each(function (Crawler $select) use (&$choices, &$groupId): void {
                    $candidateGroupId = $this->groupId(
                        $select->attr('data-product-attribute'),
                        $select->attr('name'),
                    );

                    if ($candidateGroupId === null) {
                        return;
                    }

                    $groupId ??= $candidateGroupId;

                    $select->filter('option[value]')->each(function (Crawler $option) use (&$choices): void {
                        $choiceId = $this->stringOrNull($option->attr('value'));

                        if ($choiceId === null) {
                            return;
                        }

                        $choiceLabel = $this->text($option->text(''));

                        if ($choiceLabel === '') {
                            $choiceLabel = $choiceId;
                        }

                        $choices[$choiceId] = [
                            'id' => $choiceId,
                            'label' => $choiceLabel,
                            'selected' => $option->attr('selected') !== null,
                        ];
                    });
                });
            } catch (Throwable) {
                // No select variants.
            }

            if ($groupId === null || $choices === []) {
                return;
            }

            if ($label === '') {
                $label = 'Opcja '.$groupId;
            }

            $groups[] = [
                'id' => $groupId,
                'label' => trim($label, " \t\n\r\0\x0B:"),
                'choices' => array_values($choices),
            ];
        });

        return $groups;
    }

    /**
     * @param  list<array{id: string, label: string, choices: list<array{id: string, label: string, selected: bool>>}>  $groups
     * @return list<array{group_id: string, group_label: string, choice_id: string, choice_label: string}>
     */
    private function selectedVariantAttributes(array $groups): array
    {
        $attributes = [];

        foreach ($groups as $group) {
            $selected = null;

            foreach ($group['choices'] as $choice) {
                if ($choice['selected']) {
                    $selected = $choice;
                    break;
                }
            }

            if ($selected === null) {
                continue;
            }

            $attributes[] = [
                'label' => $group['label'],
                'value' => $selected['label'],
            ];
        }

        return $attributes;
    }

    /**
     * @param  list<array{id: string, label: string, choices: list<array{id: string, label: string, selected: bool>>}>  $groups
     * @return list<list<array{group_id: string, group_label: string, choice_id: string, choice_label: string}>>
     */
    private function cartesianCombinations(array $groups): array
    {
        $combinations = [[]];

        foreach ($groups as $group) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($group['choices'] as $choice) {
                    $next[] = array_merge($combination, [[
                        'group_id' => $group['id'],
                        'group_label' => $group['label'],
                        'choice_id' => $choice['id'],
                        'choice_label' => $choice['label'],
                    ]]);
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @return array<string, string>
     */
    private function features(Crawler $crawler): array
    {
        $features = [];

        try {
            $terms = $crawler->filter('.product-features dl.data-sheet dt, .product-features dt, dl.data-sheet dt');

            $terms->each(function (Crawler $term) use (&$features): void {
                $label = $this->text($term->text(''));
                $domNode = $term->getNode(0);

                if ($label === '' || ! $domNode instanceof DOMElement) {
                    return;
                }

                $sibling = $domNode->nextElementSibling;

                if (! $sibling instanceof DOMElement) {
                    return;
                }

                $value = $this->text($sibling->textContent ?? '');

                if ($value !== '') {
                    $features[$label] = $value;
                }
            });
        } catch (Throwable) {
            // Table fallback below.
        }

        try {
            $crawler->filter('.product-features tr, #product-details tr')->each(
                function (Crawler $row) use (&$features): void {
                    $cells = $row->filter('th,td');

                    if ($cells->count() < 2) {
                        return;
                    }

                    $label = $this->text($cells->eq(0)->text(''));
                    $value = $this->text($cells->eq(1)->text(''));

                    if ($label !== '' && $value !== '') {
                        $features[$label] = $value;
                    }
                }
            );
        } catch (Throwable) {
            // Feature tables are optional.
        }

        return $features;
    }

    /**
     * @param  array<string, string>  $features
     * @return list<array{label: string, value: string}>
     */
    private function attributeRows(array $features, ?string $brand): array
    {
        $rows = [];

        foreach ($features as $label => $value) {
            if (in_array(mb_strtolower($label), ['indeks', 'ean', 'ean13'], true)) {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $value];
        }

        if ($brand !== null && $this->feature($features, 'Marka') === null) {
            $rows[] = ['label' => 'Marka', 'value' => $brand];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $features
     */
    private function feature(array $features, string $wanted): ?string
    {
        $wantedKey = $this->comparable($wanted);

        foreach ($features as $label => $value) {
            if ($this->comparable($label) === $wantedKey) {
                return $this->stringOrNull($value);
            }
        }

        return null;
    }

    /**
     * @return array{0: ?float, 1: ?int}
     */
    private function priceAndVat(Crawler $crawler, ?array $jsonLd): array
    {
        $fragments = [];

        foreach ([
            '.product-prices',
            '#product-prices',
            '.current-price',
            '[itemprop="offers"]',
            '.product-information',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() > 0) {
                    $fragments[] = $node->text('');
                }
            } catch (Throwable) {
                // Keep trying.
            }
        }

        $text = $this->text(implode(' ', $fragments));

        if ($text === '') {
            $text = $this->text($crawler->text(''));
        }

        [$price, $vat] = $this->priceAndVatFromText($text);

        if ($price === null) {
            $price = $this->jsonLdPrice($jsonLd);
        }

        return [$price, $vat];
    }

    /**
     * @return array{0: ?float, 1: ?int}
     */
    private function priceAndVatFromFragment(string $html): array
    {
        return $this->priceAndVatFromText($this->text(strip_tags($html)));
    }

    /**
     * @return array{0: ?float, 1: ?int}
     */
    private function priceAndVatFromText(string $text): array
    {
        $vat = null;
        $price = null;

        if (preg_match('/z\s+VAT\s+(0|5|8|23)\s*%/iu', $text, $matches) === 1) {
            $vat = (int) $matches[1];

            $offset = strpos($text, $matches[0]);

            if ($offset !== false) {
                $afterVat = substr($text, $offset + strlen($matches[0]));

                if (preg_match('/([0-9][0-9\s.]*(?:,[0-9]{2}|\.[0-9]{2}))\s*zł/iu', $afterVat, $priceMatch) === 1) {
                    $price = $this->decimalAmount($priceMatch[1]);
                }
            }
        }

        if ($price === null
            && preg_match('/(?:Cena[^0-9]{0,80})?([0-9][0-9\s.]*(?:,[0-9]{2}|\.[0-9]{2}))\s*zł/iu', $text, $matches) === 1) {
            $price = $this->decimalAmount($matches[1]);
        }

        return [$price, $vat];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonLdProduct(Crawler $crawler): ?array
    {
        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');
        } catch (Throwable) {
            return null;
        }

        foreach ($scripts as $script) {
            $raw = $script->textContent ?? '';

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            try {
                $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            $product = $this->findJsonLdProduct($decoded);

            if ($product !== null) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findJsonLdProduct(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $type = $value['@type'] ?? null;

        if ((is_string($type) && mb_strtolower($type) === 'product')
            || (is_array($type) && in_array('Product', $type, true))) {
            return $value;
        }

        foreach ($value as $child) {
            $found = $this->findJsonLdProduct($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function jsonLdPrice(?array $jsonLd): ?float
    {
        if ($jsonLd === null) {
            return null;
        }

        $offers = $jsonLd['offers'] ?? null;

        if (is_array($offers) && ! array_is_list($offers)) {
            $offers = [$offers];
        }

        if (! is_array($offers)) {
            return null;
        }

        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $price = $this->decimalAmount($offer['price'] ?? null);

            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    private function jsonLdBrand(?array $jsonLd): ?string
    {
        if ($jsonLd === null) {
            return null;
        }

        $brand = $jsonLd['brand'] ?? null;

        if (is_string($brand)) {
            return $this->stringOrNull($brand);
        }

        return is_array($brand)
            ? $this->stringOrNull($brand['name'] ?? null)
            : null;
    }

    private function jsonLdSku(?array $jsonLd): ?string
    {
        return $jsonLd !== null ? $this->stringOrNull($jsonLd['sku'] ?? null) : null;
    }

    private function jsonLdGtin(?array $jsonLd): ?string
    {
        if ($jsonLd === null) {
            return null;
        }

        foreach (['gtin13', 'gtin', 'ean13'] as $key) {
            $value = $this->stringOrNull($jsonLd[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function productName(Crawler $crawler): string
    {
        foreach (['h1[itemprop="name"]', 'h1.h1', 'main h1', 'h1'] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() > 0) {
                    $value = $this->text($node->text(''));

                    if ($value !== '') {
                        return $value;
                    }
                }
            } catch (Throwable) {
                // Try next selector.
            }
        }

        return '';
    }

    private function canonicalUrl(Crawler $crawler, string $sourceUrl): ?string
    {
        try {
            $node = $crawler->filter('link[rel="canonical"][href]')->first();

            if ($node->count() > 0) {
                return $this->normalizeProductUrl((string) $node->attr('href'), $sourceUrl);
            }
        } catch (Throwable) {
            // Fall through.
        }

        return $this->normalizeProductUrl($sourceUrl);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function idsFromUrl(string $url): array
    {
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        if (preg_match('/_([0-9]+)-([0-9]+)$/', $path, $matches) !== 1) {
            return [null, null];
        }

        return [$matches[1], $matches[2]];
    }

    private function slugFromUrl(string $url): string
    {
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $base = basename($path);
        $slug = preg_replace('/_[0-9]+-[0-9]+$/', '', $base) ?? $base;

        return Str::slug($slug);
    }

    /**
     * @return list<string>
     */
    private function categoryPath(Crawler $crawler, string $productName): array
    {
        $values = [];

        foreach ([
            'nav.breadcrumb a',
            '.breadcrumb a',
            '[data-depth] .breadcrumb a',
            'ol.breadcrumb a',
        ] as $selector) {
            try {
                $nodes = $crawler->filter($selector);

                if ($nodes->count() === 0) {
                    continue;
                }

                $nodes->each(function (Crawler $node) use (&$values, $productName): void {
                    $value = $this->text($node->text(''));

                    if ($value === ''
                        || $this->comparable($value) === 'strona glowna'
                        || $this->comparable($value) === 'home'
                        || $this->comparable($value) === $this->comparable($productName)) {
                        return;
                    }

                    $values[] = $value;
                });

                if ($values !== []) {
                    break;
                }
            } catch (Throwable) {
                // Try next selector.
            }
        }

        return array_values(array_unique($values));
    }

    private function descriptionHtml(Crawler $crawler): ?string
    {
        foreach ([
            '#description .product-description',
            '.product-description',
            '#description',
            '[itemprop="description"]',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() === 0) {
                    continue;
                }

                $html = trim($node->html(''));

                if ($html !== '') {
                    return $html;
                }
            } catch (Throwable) {
                // Try next selector.
            }
        }

        return null;
    }

    /**
     * @return list<array{url: string, alt: string}>
     */
    private function images(Crawler $crawler, string $baseUrl, string $name): array
    {
        $images = [];

        foreach ([
            '.product-images img',
            '.images-container img',
            '.product-cover img',
            '[data-image-large-src]',
        ] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $baseUrl, $name): void {
                    $url = null;

                    foreach ([
                        'data-image-large-src',
                        'data-full-size-image-url',
                        'data-src',
                        'src',
                    ] as $attribute) {
                        $candidate = $this->absoluteUrl((string) $node->attr($attribute), $baseUrl);

                        if ($candidate !== null) {
                            $url = $candidate;
                            break;
                        }
                    }

                    if ($url === null) {
                        return;
                    }

                    $images[$url] = [
                        'url' => $url,
                        'alt' => $this->text((string) $node->attr('alt')) ?: $name,
                    ];
                });
            } catch (Throwable) {
                // Try next gallery selector.
            }
        }

        return array_values($images);
    }

    private function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://www.'.self::HOST.$url;
        } elseif (! preg_match('#^https?://#iu', $url)) {
            $url = 'https://www.'.self::HOST.'/'.ltrim($url, '/');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $host = preg_replace('/^www\./', '', mb_strtolower($host)) ?? $host;

        if ($host !== self::HOST && ! str_ends_with($host, '.'.self::HOST)) {
            return null;
        }

        return $url;
    }

    private function availability(Crawler $crawler): string
    {
        return $this->availabilityFromText($this->text($crawler->text('')));
    }

    private function availabilityLabel(Crawler $crawler): ?string
    {
        $text = $this->text($crawler->text(''));

        foreach ([
            '/Produkt\s+dostępny[^.]{0,100}/iu',
            '/Produkt\s+niedostępny[^.]{0,100}/iu',
            '/Brak\s+w\s+magazynie[^.]{0,100}/iu',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return $this->text($matches[0]);
            }
        }

        return null;
    }

    private function availabilityFromText(string $text): string
    {
        if ($this->containsComparable($text, 'produkt niedostepny')
            || $this->containsComparable($text, 'brak w magazynie')
            || $this->containsComparable($text, 'powiadom mnie kiedy bedzie dostepny')) {
            return 'out_of_stock';
        }

        if ($this->containsComparable($text, 'produkt dostepny')
            || $this->containsComparable($text, 'dodaj do koszyka')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    private function metaContent(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();

            return $node->count() > 0
                ? $this->stringOrNull($node->attr('content'))
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function titleText(Crawler $crawler): ?string
    {
        try {
            $node = $crawler->filter('title')->first();

            return $node->count() > 0 ? $this->stringOrNull($node->text('')) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{label: string, value: string}>  $attributes
     */
    private function variantLabel(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $attribute) {
            $label = $this->stringOrNull($attribute['label'] ?? null);
            $value = $this->stringOrNull($attribute['value'] ?? null);

            if ($label === null || $value === null || in_array($this->comparable($label), ['indeks', 'ean'], true)) {
                continue;
            }

            $parts[] = $label.': '.$value;
        }

        return $parts !== [] ? implode(', ', $parts) : 'Wariant domyślny';
    }

    /**
     * @param  list<array{label: string, value: string}>  $attributes
     */
    private function attributeSignature(array $attributes): string
    {
        $pairs = [];

        foreach ($attributes as $attribute) {
            $label = $this->stringOrNull($attribute['label'] ?? null);
            $value = $this->stringOrNull($attribute['value'] ?? null);

            if ($label !== null && $value !== null) {
                $pairs[] = $this->comparable($label).'='.$this->comparable($value);
            }
        }

        sort($pairs);

        return implode('|', $pairs);
    }

    /**
     * @param  list<array{group_id: string, group_label: string, choice_id: string, choice_label: string}>  $combination
     */
    private function requestedLabel(array $combination): string
    {
        return implode(', ', array_map(
            fn (array $choice): string => $choice['group_label'].': '.$choice['choice_label'],
            $combination,
        ));
    }

    private function groupId(?string $dataAttribute, ?string $name): ?string
    {
        $value = $this->stringOrNull($dataAttribute);

        if ($value !== null && ctype_digit($value)) {
            return $value;
        }

        if (is_string($name) && preg_match('/group\[([0-9]+)\]/', $name, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function choiceLabel(Crawler $input): string
    {
        foreach (['title', 'aria-label', 'data-label'] as $attribute) {
            $value = $this->text((string) $input->attr($attribute));

            if ($value !== '') {
                return $value;
            }
        }

        $domNode = $input->getNode(0);

        if ($domNode instanceof DOMElement) {
            $parent = $domNode->parentElement;

            if ($parent instanceof DOMElement && mb_strtolower($parent->tagName) === 'label') {
                $value = $this->text($parent->textContent ?? '');

                if ($value !== '') {
                    return $value;
                }
            }

            if ($domNode->hasAttribute('id')) {
                $id = $domNode->getAttribute('id');

                try {
                    $document = new Crawler($domNode->ownerDocument);

                    $label = $document->filter('label[for="'.$id.'"]')->first();

                    if ($label->count() > 0) {
                        return $this->text($label->text(''));
                    }
                } catch (Throwable) {
                    // Fall through.
                }
            }
        }

        return '';
    }

    private function candidateIsCommerceComplete(mixed $candidate): bool
    {
        if (! is_array($candidate)) {
            return false;
        }

        return $this->stringOrNull($candidate['external_variant_id'] ?? null) !== null
            && $this->decimalAmount($candidate['price_gross_amount'] ?? null) !== null
            && $this->vatRateValue($candidate['vat_rate'] ?? null) !== null;
    }

    private function vatRateValue(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        return is_int($value) && in_array($value, [0, 5, 8, 23], true)
            ? $value
            : null;
    }

    private function decimalAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function yesValue(?string $value): bool
    {
        return in_array($this->comparable($value ?? ''), ['tak', 'yes', 'true', '1'], true);
    }

    private function containsComparable(string $haystack, string $needle): bool
    {
        return str_contains($this->comparable($haystack), $this->comparable($needle));
    }

    private function comparable(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = $this->text((string) $value);

        return $value !== '' ? $value : null;
    }

    private function get(string $url): ?Response
    {
        $this->pauseBeforeRequest();

        try {
            return Http::connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->withHeaders($this->headers())
                ->get($url);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function postRefresh(string $url, array $parameters): ?Response
    {
        $this->pauseBeforeRequest();

        try {
            return Http::connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->withHeaders($this->headers())
                ->asForm()
                ->post($url, $parameters);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/json',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.5',
            'User-Agent' => 'KonjiShopCatalogCrawler/1.0 (+https://ortezka.pl)',
            'X-Requested-With' => 'XMLHttpRequest',
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

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function failedResult(string $url, string $reason, ?array $context): array
    {
        return [
            'source' => 'seni24',
            'source_url' => $url,
            'canonical_url' => null,
            'external_product_id' => null,
            'slug' => '',
            'name' => '',
            'price_gross_amount' => null,
            'currency' => 'PLN',
            'vat_rate' => null,
            'availability' => 'unknown',
            'source_category_path' => [],
            'categories' => [],
            'description_html' => null,
            'description_plain' => '',
            'images' => [],
            'attributes' => [],
            'variant_candidates' => [],
            'variant_resolution_complete' => false,
            'is_medical_device' => false,
            'medical_device_class' => null,
            'is_refundable' => false,
            'raw_context' => $context,
            'warnings' => [],
            'failed_urls' => [$url => $reason],
        ];
    }
}
