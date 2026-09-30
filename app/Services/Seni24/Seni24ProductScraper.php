<?php

declare(strict_types=1);

namespace App\Services\Seni24;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class Seni24ProductScraper
{
    private const HOSTS = ['www.seni24.pl', 'seni24.pl'];

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

    /** @param array<string,mixed>|null $context
     *  @return array<string,mixed>
     */
    public function scrape(string $url, ?array $context = null): array
    {
        $normalized = $this->normalizeProductUrl($url);
        if ($normalized === null) {
            return $this->failedResult($url, 'invalid_seni24_product_url', $context);
        }

        $this->emit('Fetching Seni24 product page: '.$normalized);
        $this->pauseBeforeRequest();

        try {
            $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->withHeaders($this->headers())
                ->get($normalized);
        } catch (Throwable $exception) {
            return $this->failedResult($normalized, $exception->getMessage(), $context);
        }

        if (!$response->successful()) {
            return $this->failedResult($normalized, 'HTTP '.$response->status(), $context);
        }

        $result = $this->extract(
            $response->body(),
            $normalized,
            $context,
        );

        /*
         * Seni24 can occasionally answer a real product URL with HTTP 200
         * while rendering a generic store/fallback page.
         *
         * extract() intentionally remains a permissive parser because it is
         * also used with partial HTML fixtures. Live scrape() calls, however,
         * must fail closed when the response contains no authoritative
         * product/commerce evidence.
         */
        if (! $this->hasAuthoritativeProductEvidence($result)) {
            return $this->failedResult(
                $normalized,
                'non_product_fallback_page',
                $context,
            );
        }

        $rawContext = is_array($result['raw_context'] ?? null)
            ? $result['raw_context']
            : [];

        if (($rawContext['structured_variant_recovery_required'] ?? false) === true) {
            $result = $this->hydrateStructuredVariantCandidates($result);
        }

        return $result;
    }

    /** @param array<string,mixed>|null $context
     *  @return array<string,mixed>
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
        [$externalProductId, $externalVariantId] = $this->idsFromUrl($identityUrl);

        if ($externalProductId === null || $externalVariantId === null) {
            return $this->failedResult($sourceUrl, 'missing_seni24_numeric_identity', $context);
        }

        $name = $this->firstText($crawler, ['h1', '[itemprop="name"]']);
        $bodyText = $this->normalizeText($crawler->filter('body')->count() > 0
            ? $crawler->filter('body')->text('')
            : $crawler->text(''));

        $price = $this->priceGrossAmount($bodyText);
        $vatRate = $this->vatRate($bodyText);
        $availabilityData = $this->availabilityData($crawler, $bodyText);
        $availabilityLabel = $availabilityData['label'];
        $availability = $availabilityData['status'];
        $isOnOrder = $availability === 'on_order';
        $features = $this->featureAttributes($crawler, $bodyText);
        $categoryPath = $this->categoryPath($crawler, $name);
        $variantData = $this->variantData($crawler);
        $descriptionHtml = $this->descriptionHtml($crawler);
        $descriptionPlain = $this->normalizeText(strip_tags((string) $descriptionHtml));
        $catalogueNumber = $this->attributeValue($features, 'Indeks');
        $ean = $this->attributeValue($features, 'ean13')
            ?? $this->attributeValue($features, 'EAN');

        $structuredVariantCandidates = $this->structuredVariantCandidates(
            $crawler,
            $externalProductId,
            $externalVariantId,
            $variantData['groups'],
            $vatRate,
        );

        $structuredVariantContradictedSingleOptionKeys =
            $this->contradictedStructuredSingleOptionGroupKeys(
                $structuredVariantCandidates,
                $variantData['groups'],
            );

        $structuredVariantHasContradictedSingleOptions =
            $structuredVariantContradictedSingleOptionKeys !== [];

        $structuredVariantCandidates = $this->enrichStructuredVariantAttributes(
            $structuredVariantCandidates,
            $externalVariantId,
            $variantData['selected_attributes'],
            $variantData['groups'],
        );

        foreach ($structuredVariantCandidates as &$candidate) {
            if (($candidate['external_variant_id'] ?? null) !== $externalVariantId) {
                continue;
            }

            $structuredPrice = $candidate['price_gross_amount'] ?? null;

            /*
             * The visible "Cena za 1 opak." price is authoritative for
             * the currently selected combination.
             *
             * Seni24 structured data may expose a lower quantity-tier
             * price, e.g. "Cena od 2 opak.", so it must never overwrite
             * a valid visible one-package price.
             */
            if ($price !== null) {
                $candidate['price_gross_amount'] = $price;
            } elseif (is_int($structuredPrice) || is_float($structuredPrice)) {
                $price = (float) $structuredPrice;
            } elseif (is_string($structuredPrice)
                && trim($structuredPrice) !== ''
                && is_numeric(str_replace(',', '.', trim($structuredPrice)))) {
                $price = (float) str_replace(',', '.', trim($structuredPrice));
            }

            $structuredCatalogueNumber = is_string(
                $candidate['catalogue_number'] ?? null
            )
                ? $this->normalizeText($candidate['catalogue_number'])
                : '';

            if ($structuredCatalogueNumber !== '') {
                $catalogueNumber = $structuredCatalogueNumber;
            }

            $structuredEan = is_string($candidate['ean'] ?? null)
                ? $this->normalizeText($candidate['ean'])
                : '';

            if ($structuredEan !== '') {
                $ean = $structuredEan;
            }

            break;
        }

        unset($candidate);

        $variantsResolvedByStructuredData = $structuredVariantCandidates !== []
            && $this->structuredVariantsCoverOptions(
                $structuredVariantCandidates,
                $variantData['groups'],
            );

        $structuredVariantHasDuplicateAttributeSignatures =
            $this->hasDuplicateStructuredVariantAttributeSignatures(
                $structuredVariantCandidates,
            );

        $variantsUnresolved = $structuredVariantHasContradictedSingleOptions
            || $structuredVariantHasDuplicateAttributeSignatures
            || (
                $variantData['unresolved']
                && ! $variantsResolvedByStructuredData
            );

        $medicalValue = $this->attributeValue($features, 'Wyrób medyczny');
        $isMedicalDevice = $medicalValue !== null
            && in_array(Str::lower(Str::ascii($medicalValue)), ['tak', 'yes', '1'], true);
        $brand = $this->brand($crawler, $features, $bodyText);
        $images = $this->images($crawler, $identityUrl);
        $warnings = [];

        if ($name === '') {
            $warnings[] = 'Product name not found.';
        }
        if ($price === null) {
            $warnings[] = 'Authoritative Seni24 gross price not found.';
        }
        if ($vatRate === null) {
            $warnings[] = 'Explicit Seni24 VAT rate not found.';
        }
        if ($catalogueNumber === null) {
            $warnings[] = 'Seni24 product index not found.';
        }
        if ($variantsUnresolved) {
            $warnings[] = 'Seni24 product exposes additional variant choices whose authoritative combination prices were not resolved.';
        }
        if ($images === []) {
            $warnings[] = 'No Seni24 product-gallery images found.';
        }

        $selectedAttributes = $variantData['selected_attributes'];
        $variantLabel = $selectedAttributes === []
            ? ($catalogueNumber ?? $externalVariantId)
            : implode(', ', array_map(
                static fn (array $a): string => $a['label'].': '.$a['value'],
                $selectedAttributes,
            ));

        $selectedVariantCandidate = [
            'external_variant_id' => $externalVariantId,
            'label' => $variantLabel,
            'attributes' => $selectedAttributes,
            'catalogue_number' => $catalogueNumber,
            'ean' => $ean,
            'price_gross_amount' => $price,
            'currency' => 'PLN',
            'vat_rate' => $vatRate,
            'availability' => $availability,
            'availability_label' => $availabilityLabel,
        ];

        $variantCandidates = $structuredVariantCandidates !== []
            ? $structuredVariantCandidates
            : [$selectedVariantCandidate];

        $context = is_array($context) ? $context : [];
        $listingRoots = $this->stringList($context['listing_roots'] ?? []);
        if ($listingRoots === []) {
            $listingRoots = Seni24ProductUrlScraper::DEFAULT_URLS;
        }

        return [
            'source' => 'seni24',
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_product_id' => $externalProductId,
            'slug' => $this->slugFromUrl($identityUrl),
            'name' => $name,
            'brand' => $brand,
            'price_gross_amount' => $price,
            'currency' => 'PLN',
            'vat_rate' => $vatRate,
            'availability' => $availability,
            'availability_label' => $availabilityLabel,
            'is_on_order' => $isOnOrder,
            'shipping_time' => $this->shippingTime($bodyText),
            'unit' => $this->unitFromPriceText($bodyText),
            'catalogue_number' => $catalogueNumber,
            'ean' => $ean,
            'source_category_path' => $categoryPath,
            'categories' => $categoryPath,
            'category' => $categoryPath !== [] ? end($categoryPath) : null,
            'description_html' => $descriptionHtml,
            'description_plain' => $descriptionPlain,
            'seo_title' => $this->metaContent($crawler, 'meta[property="og:title"]')
                ?? $this->firstText($crawler, ['title'])
                ?? $name,
            'seo_description' => $this->metaContent($crawler, 'meta[name="description"]'),
            'images' => $images,
            'attributes' => $features,
            'variant_candidates' => $variantCandidates,
            'variant_options' => $variantData['groups'],
            'variants_unresolved' => $variantsUnresolved,
            'is_medical_device' => $isMedicalDevice,
            'medical_device_class' => $this->attributeValue($features, 'Klasa wyrobu medycznego'),
            'raw_context' => array_merge($context, [
                'listing_roots' => $listingRoots,
                'selected_variant_url' => $identityUrl,
                'selected_variant_id' => $externalVariantId,
                'structured_variant_recovery_required' =>
                    $structuredVariantCandidates !== []
                    && (
                        $structuredVariantHasContradictedSingleOptions
                        || $structuredVariantHasDuplicateAttributeSignatures
                    ),
                'structured_variant_recovery_keys' =>
                    array_keys(
                        $structuredVariantContradictedSingleOptionKeys,
                    ),
            ]),
            'warnings' => $warnings,
            'failed_urls' => [],
        ];
    }

    /**
     * Recover customer-visible structured variant semantics from concrete
     * combination pages.
     *
     * The requested combination path is the request identity. Seni24 may keep
     * canonical URL, page-level SKU and EAN pinned to the default combination,
     * so those page-level values are deliberately not trusted here.
     *
     * ProductGroup data continues to own candidate identity/SKU/EAN/price.
     * DOM selected attributes are accepted only when they agree with the
     * requested candidate's URL-fragment evidence.
     *
     * Recovery is all-or-nothing for the product.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function hydrateStructuredVariantCandidates(
        array $result,
    ): array {
        $context = is_array($result['raw_context'] ?? null)
            ? $result['raw_context']
            : [];

        if (($context['structured_variant_recovery_required'] ?? false) !== true) {
            return $result;
        }

        $externalProductId = is_string(
            $result['external_product_id'] ?? null
        )
            ? $this->normalizeText($result['external_product_id'])
            : '';

        $candidates = is_array($result['variant_candidates'] ?? null)
            ? $result['variant_candidates']
            : [];

        $groups = is_array($result['variant_options'] ?? null)
            ? $result['variant_options']
            : [];

        $requiredKeys = $this->stringList(
            $context['structured_variant_recovery_keys'] ?? [],
        );

        if ($externalProductId === '' || count($candidates) < 2) {
            return $this->variantHydrationFailure(
                $result,
                'missing_product_identity_or_candidates',
            );
        }

        $vatRate = is_int($result['vat_rate'] ?? null)
            ? $result['vat_rate']
            : null;

        $hydrated = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                return $this->variantHydrationFailure(
                    $result,
                    'invalid_candidate',
                );
            }

            $variantId = is_string(
                $candidate['external_variant_id'] ?? null
            )
                ? $this->normalizeText(
                    $candidate['external_variant_id'],
                )
                : '';

            $candidateSourceUrl = is_string(
                $candidate['source_url'] ?? null
            )
                ? $candidate['source_url']
                : null;

            $requestUrl = $candidateSourceUrl !== null
                ? $this->normalizeProductUrl($candidateSourceUrl)
                : null;

            if ($variantId === '' || $requestUrl === null) {
                return $this->variantHydrationFailure(
                    $result,
                    'missing_candidate_request_identity',
                );
            }

            [$requestProductId, $requestVariantId] =
                $this->idsFromUrl($requestUrl);

            if (
                $requestProductId !== $externalProductId
                || $requestVariantId !== $variantId
            ) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_request_identity_mismatch:'.$variantId,
                );
            }

            $this->emit(
                'Hydrating Seni24 combination page: '.$requestUrl,
            );
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(
                    min(5, $this->timeoutSeconds),
                )
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers())
                    ->get($requestUrl);
            } catch (Throwable) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_request_failed:'.$variantId,
                );
            }

            if (! $response->successful()) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_http_'.$response->status().':'.$variantId,
                );
            }

            try {
                $crawler = new Crawler(
                    $response->body(),
                    $requestUrl,
                );
            } catch (Throwable) {
                return $this->variantHydrationFailure(
                    $result,
                    'invalid_candidate_html:'.$variantId,
                );
            }

            $bodyText = $this->normalizeText(
                $crawler->filter('body')->count() > 0
                    ? $crawler->filter('body')->text('')
                    : $crawler->text(''),
            );

            $pageVariantData = $this->variantData($crawler);

            $selectedAttributes = array_values(
                array_filter(
                    $pageVariantData['selected_attributes'],
                    fn (array $attribute): bool =>
                        is_string($attribute['value'] ?? null)
                        && ! $this->isVariantPlaceholderValue(
                            $attribute['value'],
                        ),
                ),
            );

            if ($selectedAttributes === []) {
                return $this->variantHydrationFailure(
                    $result,
                    'missing_selected_dom_attributes:'.$variantId,
                );
            }

            /*
             * Validate the combination-specific visible selling price
             * against the candidate price already proven by ProductGroup.
             */
            $visiblePrice = $this->priceGrossAmount($bodyText);
            $expectedPrice = $this->numericPriceValue(
                $candidate['price_gross_amount'] ?? null,
            );

            if (
                $visiblePrice === null
                || $expectedPrice === null
            ) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_visible_price_mismatch:'.$variantId,
                );
            }

            $visiblePriceDiffers =
                abs($visiblePrice - $expectedPrice) > 0.011;

            /*
             * Re-read ProductGroup from this response, selecting by the
             * REQUESTED combination ID rather than canonical/default ID.
             * This validates source identity without trusting stale page
             * feature metadata.
             */
            $pageVatRate = $this->vatRate($bodyText)
                ?? $vatRate;

            $pageStructuredCandidates =
                $this->structuredVariantCandidates(
                    $crawler,
                    $externalProductId,
                    $variantId,
                    $pageVariantData['groups'],
                    $pageVatRate,
                );

            $pageIdentityCandidate = null;

            foreach ($pageStructuredCandidates as $pageCandidate) {
                if (
                    is_array($pageCandidate)
                    && (string) (
                        $pageCandidate['external_variant_id'] ?? ''
                    ) === $variantId
                ) {
                    $pageIdentityCandidate = $pageCandidate;
                    break;
                }
            }

            if ($pageIdentityCandidate === null) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_missing_from_product_group:'.$variantId,
                );
            }

            foreach (['catalogue_number', 'ean'] as $identityKey) {
                $expectedIdentity = is_string(
                    $candidate[$identityKey] ?? null
                )
                    ? $this->normalizeText(
                        $candidate[$identityKey],
                    )
                    : '';

                $pageIdentity = is_string(
                    $pageIdentityCandidate[$identityKey] ?? null
                )
                    ? $this->normalizeText(
                        $pageIdentityCandidate[$identityKey],
                    )
                    : '';

                if (
                    $expectedIdentity !== ''
                    && $pageIdentity !== $expectedIdentity
                ) {
                    return $this->variantHydrationFailure(
                        $result,
                        'candidate_structured_identity_mismatch:'
                            .$variantId.':'.$identityKey,
                    );
                }
            }

            $pageStructuredPrice = $this->numericPriceValue(
                $pageIdentityCandidate[
                    'price_gross_amount'
                ] ?? null,
            );

            if ($pageStructuredPrice === null) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_structured_price_mismatch:'.$variantId,
                );
            }

            $pageStructuredPriceDiffers =
                abs(
                    $pageStructuredPrice - $expectedPrice
                ) > 0.011;

            /*
             * The original product page may expose stale ProductGroup
             * pricing. A concrete combination page is allowed to replace
             * that price only when its own ProductGroup price and visible
             * one-package price independently agree.
             *
             * Candidate identity and customer semantics are still
             * validated below before anything is accepted.
             */
            if (
                $pageStructuredPriceDiffers
                && abs(
                    $pageStructuredPrice - $visiblePrice
                ) > 0.011
            ) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_structured_price_mismatch:'.$variantId,
                );
            }

            /*
             * If ProductGroup did NOT change but only the visible price
             * changed, retain the stricter exact-page identity proof.
             */
            if (
                ! $pageStructuredPriceDiffers
                && $visiblePriceDiffers
                && ! $this->combinationPageHasExactIdentity(
                    $crawler,
                    $requestUrl,
                    $externalProductId,
                    $variantId,
                    $candidate,
                    $bodyText,
                )
            ) {
                return $this->variantHydrationFailure(
                    $result,
                    'candidate_visible_price_mismatch:'.$variantId,
                );
            }

            /*
             * URL fragments are validation evidence only.
             * They never become imported attribute values.
             */
            $fragmentValues = $this->variantFragmentMap(
                $candidateSourceUrl,
            );

            $selectedByKey = [];

            foreach ($selectedAttributes as $attribute) {
                if (
                    ! is_array($attribute)
                    || ! is_string($attribute['label'] ?? null)
                    || ! is_string($attribute['value'] ?? null)
                ) {
                    continue;
                }

                $key = $this->variantFragmentKey(
                    $attribute['label'],
                );

                if ($key !== '') {
                    $selectedByKey[$key] = $attribute;
                }
            }

            $candidateByKey = [];

            foreach (($candidate['attributes'] ?? []) as $attribute) {
                if (
                    ! is_array($attribute)
                    || ! is_string($attribute['label'] ?? null)
                    || ! is_string($attribute['value'] ?? null)
                    || $this->isVariantPlaceholderValue(
                        $attribute['value'],
                    )
                ) {
                    continue;
                }

                $key = $this->variantFragmentKey(
                    $attribute['label'],
                );

                if ($key !== '') {
                    $candidateByKey[$key] = $attribute;
                }
            }

            foreach ($requiredKeys as $requiredKey) {
                if (! isset($fragmentValues[$requiredKey])) {
                    return $this->variantHydrationFailure(
                        $result,
                        'candidate_missing_required_semantic:'
                            .$variantId.':'.$requiredKey,
                    );
                }

                if (isset($selectedByKey[$requiredKey])) {
                    continue;
                }

                $structuredAttribute =
                    $candidateByKey[$requiredKey] ?? null;

                if (
                    ! is_array($structuredAttribute)
                    || ! is_string(
                        $structuredAttribute['value'] ?? null
                    )
                    || $this->variantFragmentComparable(
                        $structuredAttribute['value'],
                    )
                        !==
                        $this->variantFragmentComparable(
                            $fragmentValues[$requiredKey],
                        )
                ) {
                    return $this->variantHydrationFailure(
                        $result,
                        'candidate_missing_required_semantic:'
                            .$variantId.':'.$requiredKey,
                    );
                }
            }

            foreach ($selectedByKey as $key => $attribute) {
                if (! isset($fragmentValues[$key])) {
                    continue;
                }

                if (
                    $this->variantFragmentComparable(
                        $attribute['value'],
                    )
                    !==
                    $this->variantFragmentComparable(
                        $fragmentValues[$key],
                    )
                ) {
                    return $this->variantHydrationFailure(
                        $result,
                        'candidate_fragment_semantic_mismatch:'
                            .$variantId.':'.$key,
                    );
                }
            }

            $attributes = [];

            foreach (($candidate['attributes'] ?? []) as $attribute) {
                if (
                    ! is_array($attribute)
                    || ! is_string($attribute['label'] ?? null)
                    || ! is_string($attribute['value'] ?? null)
                ) {
                    continue;
                }

                $attributes[
                    Str::lower(Str::ascii($attribute['label']))
                ] = $attribute;
            }

            foreach ($selectedAttributes as $attribute) {
                if (
                    ! is_array($attribute)
                    || ! is_string($attribute['label'] ?? null)
                    || ! is_string($attribute['value'] ?? null)
                ) {
                    continue;
                }

                $attributes[
                    Str::lower(Str::ascii($attribute['label']))
                ] = [
                    'label' => $attribute['label'],
                    'value' => $attribute['value'],
                ];
            }

            $candidate['attributes'] = array_values($attributes);

            if ($pageStructuredPriceDiffers) {
                $candidate['price_gross_amount'] =
                    $pageStructuredPrice;
            } elseif ($visiblePriceDiffers) {
                $candidate['price_gross_amount'] =
                    $visiblePrice;
            }

            $candidate['label'] = implode(
                ', ',
                array_map(
                    static fn (array $attribute): string =>
                        $attribute['label'].': '.$attribute['value'],
                    $candidate['attributes'],
                ),
            );

            $hydrated[] = $candidate;
        }

        if (
            ! $this->structuredVariantsCoverOptions(
                $hydrated,
                $groups,
            )
        ) {
            return $this->variantHydrationFailure(
                $result,
                'hydrated_candidates_do_not_cover_options',
            );
        }

        if (
            $this->hasDuplicateStructuredVariantAttributeSignatures(
                $hydrated,
            )
        ) {
            return $this->variantHydrationFailure(
                $result,
                'hydrated_candidates_not_customer_distinguishable',
            );
        }

        $result['variant_candidates'] = $hydrated;
        $result['variants_unresolved'] = false;

        $warning =
            'Seni24 product exposes additional variant choices '
            .'whose authoritative combination prices were not resolved.';

        $result['warnings'] = array_values(
            array_filter(
                is_array($result['warnings'] ?? null)
                    ? $result['warnings']
                    : [],
                static fn (mixed $value): bool =>
                    $value !== $warning,
            ),
        );

        $context['structured_variant_hydration'] = [
            'attempted' => true,
            'succeeded' => true,
            'candidate_count' => count($hydrated),
        ];

        $result['raw_context'] = $context;

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function variantHydrationFailure(
        array $result,
        string $reason,
    ): array {
        $context = is_array($result['raw_context'] ?? null)
            ? $result['raw_context']
            : [];

        $context['structured_variant_hydration'] = [
            'attempted' => true,
            'succeeded' => false,
            'reason' => $reason,
        ];

        $result['raw_context'] = $context;

        return $result;
    }

    /**
     * Prove that this response really represents the requested combination
     * before allowing visible commerce data to override stale ProductGroup
     * commerce data.
     *
     * We require:
     * - canonical product/combination IDs equal the request, and
     * - every available stable candidate identity field (SKU/EAN) agrees,
     * - with at least one stable identity field actually present.
     *
     * @param array<string,mixed> $candidate
     */
    private function combinationPageHasExactIdentity(
        Crawler $crawler,
        string $requestUrl,
        string $externalProductId,
        string $variantId,
        array $candidate,
        string $bodyText,
    ): bool {
        $canonicalUrl = $this->canonicalUrl(
            $crawler,
            $requestUrl,
        );

        if ($canonicalUrl === null) {
            return false;
        }

        [$canonicalProductId, $canonicalVariantId] =
            $this->idsFromUrl($canonicalUrl);

        if (
            $canonicalProductId !== $externalProductId
            || $canonicalVariantId !== $variantId
        ) {
            return false;
        }

        $features = $this->featureAttributes(
            $crawler,
            $bodyText,
        );

        $pageIdentities = [
            'catalogue_number' =>
                $this->attributeValue(
                    $features,
                    'Indeks',
                ),

            'ean' =>
                $this->attributeValue(
                    $features,
                    'ean13',
                )
                ?? $this->attributeValue(
                    $features,
                    'EAN',
                ),
        ];

        $matched = 0;

        foreach (
            [
                'catalogue_number',
                'ean',
            ] as $identityKey
        ) {
            $expected = is_string(
                $candidate[$identityKey] ?? null
            )
                ? $this->normalizeText(
                    $candidate[$identityKey],
                )
                : '';

            if ($expected === '') {
                continue;
            }

            $actual = is_string(
                $pageIdentities[$identityKey] ?? null
            )
                ? $this->normalizeText(
                    $pageIdentities[$identityKey],
                )
                : '';

            if (
                $actual === ''
                || ! $this->sameComparable(
                    $actual,
                    $expected,
                )
            ) {
                return false;
            }

            $matched++;
        }

        return $matched > 0;
    }

    private function isVariantPlaceholderValue(
        string $value,
    ): bool {
        return $this->normalizeText($value) === '0';
    }

    /**
     * @param array<string,mixed> $result
     */
    /**
     * Live HTTP responses must contain independent evidence that the page
     * really represents the requested Seni24 product.
     *
     * A price alone is insufficient: generic category/fallback pages can
     * contain unrelated prices while still being served with HTTP 200.
     *
     * @param array<string,mixed> $result
     */
    private function hasAuthoritativeProductEvidence(
        array $result,
    ): bool {
        $hasPrice =
            $this->numericPriceValue(
                $result['price_gross_amount'] ?? null,
            ) !== null;

        $vatRate = $result['vat_rate'] ?? null;

        $hasExplicitVat =
            is_int($vatRate)
            && in_array(
                $vatRate,
                [0, 5, 8, 23],
                true,
            );

        $availability = is_string(
            $result['availability'] ?? null
        )
            ? $result['availability']
            : '';

        $hasMachineAvailability = in_array(
            $availability,
            [
                'in_stock',
                'out_of_stock',
                'on_order',
            ],
            true,
        );

        $hasStableIdentity = false;

        foreach (['catalogue_number', 'ean'] as $key) {
            if (
                is_string($result[$key] ?? null)
                && $this->normalizeText(
                    $result[$key],
                ) !== ''
            ) {
                $hasStableIdentity = true;
                break;
            }
        }

        $hasImages =
            is_array($result['images'] ?? null)
            && $result['images'] !== [];

        $hasStructuredIdentity = false;

        foreach (
            is_array($result['variant_candidates'] ?? null)
                ? $result['variant_candidates']
                : []
            as $candidate
        ) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach (
                [
                    'source_url',
                    'catalogue_number',
                    'ean',
                ] as $key
            ) {
                if (
                    is_string($candidate[$key] ?? null)
                    && $this->normalizeText(
                        $candidate[$key],
                    ) !== ''
                ) {
                    $hasStructuredIdentity = true;
                    break 2;
                }
            }
        }

        /*
         * Strong independent product evidence is sufficient on its own.
         */
        if (
            $hasStableIdentity
            || $hasImages
            || $hasStructuredIdentity
        ) {
            return true;
        }

        /*
         * For sparse products, price must be corroborated by another
         * commerce-specific signal. An incidental category-page price
         * alone must never qualify.
         */
        return $hasPrice
            && (
                $hasExplicitVat
                || $hasMachineAvailability
            );
    }

    private function numericPriceValue(mixed $value): ?float
    {
        if (
            ! is_string($value)
            && ! is_int($value)
            && ! is_float($value)
        ) {
            return null;
        }

        if (
            is_string($value)
            && trim($value) === ''
        ) {
            return null;
        }

        $normalized = str_replace(
            ',',
            '.',
            trim((string) $value),
        );

        return is_numeric($normalized)
            ? (float) $normalized
            : null;
    }

    private function variantFragmentComparable(
        string $value,
    ): string {
        $value = Str::lower(
            Str::ascii(
                $this->normalizeText(
                    rawurldecode($value),
                ),
            ),
        );

        return preg_replace(
            '/[^a-z0-9]+/',
            '',
            $value,
        ) ?? $value;
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->absoluteUrl($url, $baseUrl);
        if ($absolute === null) {
            return null;
        }

        $parts = parse_url($absolute);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/');

        if (preg_match('/_[0-9]+-[0-9]+(?:\\.html)?$/u', $path) !== 1) {
            return null;
        }

        return 'https://www.seni24.pl'.$path;
    }

    /** @return array{0:?string,1:?string} */
    private function idsFromUrl(string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('/_([0-9]+)-([0-9]+)(?:\\.html)?$/u', $path, $m) !== 1) {
            return [null, null];
        }

        return [$m[1], $m[2]];
    }

    private function slugFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $base = basename($path);
        return (string) preg_replace('/_[0-9]+-[0-9]+(?:\\.html)?$/u', '', $base);
    }

    private function canonicalUrl(Crawler $crawler, string $fallback): ?string
    {
        try {
            $node = $crawler->filter('link[rel="canonical"][href]')->first();
            if ($node->count() > 0) {
                return $this->normalizeProductUrl((string) $node->attr('href'), $fallback);
            }
        } catch (Throwable) {
        }

        return $this->normalizeProductUrl($fallback);
    }

    /** @return list<string> */
    private function categoryPath(
        Crawler $crawler,
        string $productName = '',
    ): array {
        $productKey = Str::lower(
            Str::ascii($this->normalizeText($productName))
        );

        $selectors = [
            '.breadcrumb a',
            'nav[aria-label*="breadcrumb" i] a',
            '[class*="breadcrumb"] a',
        ];

        foreach ($selectors as $selector) {
            try {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() === 0) {
                    continue;
                }

                $path = [];
                $nodes->each(
                    function (Crawler $node) use (&$path, $productKey): void {
                        $value = $this->normalizeText($node->text(''));
                        $key = Str::lower(Str::ascii($value));

                        if ($value === ''
                            || in_array($key, ['strona glowna', 'sklep'], true)
                            || ($productKey !== '' && $key === $productKey)) {
                            return;
                        }

                        if (! in_array($value, $path, true)) {
                            $path[] = $value;
                        }
                    }
                );

                if ($path !== []) {
                    return $path;
                }
            } catch (Throwable) {
            }
        }

        return [];
    }

    private function priceGrossAmount(string $text): ?float
    {
        if (preg_match(
            '/Cena\s+(?:za\s+)?(?:1\s+)?opak\.?.{0,120}?'
            .'z\s+VAT\s+(?:0|5|8|23)\s*%(.{0,300})/iu',
            $text,
            $matches,
        ) === 1) {
            $segment = $matches[1];

            $parts = preg_split(
                '/(?:Cena\s+regularna|Najniższa\s+cena|'
                .'Produkt\s+(?:nie)?dostępny|'
                .'Przewidywany\s+czas\s+realizacji)/iu',
                $segment,
                2,
            );

            if (is_array($parts) && isset($parts[0])) {
                $segment = $parts[0];
            }

            // Promotions are rendered as e.g.
            // "RABAT 0,11 zł 8,99 zł (...)". The rebate is not the
            // commerce price and must never win the parser.
            $segment = preg_replace(
                '/RABAT\s+\d{1,7}(?:[ .]\d{3})*[,.]\d{2}\s*zł/iu',
                ' ',
                $segment,
            ) ?? $segment;

            // Seni24 normally renders the actual selling price immediately
            // before the parenthesised unit price.
            if (preg_match(
                '/(\d{1,7}(?:[ .]\d{3})*[,.]\d{2})\s*zł\s*\(/iu',
                $segment,
                $priceMatch,
            ) === 1) {
                return $this->money($priceMatch[1]);
            }

            // Safe fallback for products without a displayed unit-price
            // parenthesis, after promotional rebate values were removed.
            if (preg_match(
                '/(\d{1,7}(?:[ .]\d{3})*[,.]\d{2})\s*zł/iu',
                $segment,
                $priceMatch,
            ) === 1) {
                return $this->money($priceMatch[1]);
            }
        }

        if (preg_match(
            '/Cena\s+(?:za\s+)?(?:1\s+)?opak\.?.{0,80}?'
            .'(\d{1,7}(?:[ .]\d{3})*[,.]\d{2})\s*zł/iu',
            $text,
            $matches,
        ) === 1) {
            return $this->money($matches[1]);
        }

        return null;
    }

    private function vatRate(string $text): ?int
    {
        /*
         * Prefer the normal Seni24 one-package pricing block.
         */
        if (preg_match(
            '/Cena\s+(?:za\s+)?(?:1\s+)?opak\.?.{0,120}?'
            .'z\s+VAT\s+(0|5|8|23)\s*%/iu',
            $text,
            $matches,
        ) === 1) {
            return (int) $matches[1];
        }

        /*
         * Some Seni24 products expose VAT only in a variant-pricing
         * table heading, for example "Cena (z vat 8%)".
         *
         * Use that page-wide fallback only when every explicit VAT
         * marker agrees on exactly one supported VAT rate.
         */
        if (preg_match_all(
            '/z\s+VAT\s+(0|5|8|23)\s*%/iu',
            $text,
            $matches,
        ) > 0) {
            $rates = array_values(array_unique(array_map(
                static fn (string $rate): int => (int) $rate,
                $matches[1],
            )));

            if (count($rates) === 1) {
                return $rates[0];
            }
        }

        return null;
    }

    private function money(string $value): float
    {
        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        return (float) str_replace(',', '.', $value);
    }

    /** @return array{label:?string,status:string} */
    private function availabilityData(Crawler $crawler, string $bodyText): array
    {
        foreach ([
            ['#availability-data[data-availability]', 'data-availability'],
            ['#availability-link[href]', 'href'],
            ['meta[property="og:availability"][content]', 'content'],
        ] as [$selector, $attribute]) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() === 0) {
                    continue;
                }

                $status = $this->availabilityFromMachineValue(
                    (string) $node->attr($attribute)
                );

                if ($status !== null) {
                    return [
                        'label' => match ($status) {
                            'in_stock' => 'Produkt dostępny',
                            'out_of_stock' => 'Produkt niedostępny',
                            'on_order' => 'Produkt na zamówienie',
                            default => null,
                        },
                        'status' => $status,
                    ];
                }
            } catch (Throwable) {
            }
        }

        foreach ([
            '#availability-text .h6',
            '.product-availability-status .h6',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() === 0) {
                    continue;
                }

                $label = $this->normalizeText($node->text(''));

                if ($label !== '') {
                    return [
                        'label' => $label,
                        'status' => $this->availability($label),
                    ];
                }
            } catch (Throwable) {
            }
        }

        $label = $this->availabilityLabel($bodyText);

        return [
            'label' => $label,
            'status' => $this->availability($label),
        ];
    }

    private function availabilityFromMachineValue(string $value): ?string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^a-z]+/', '', $value) ?? $value;

        if (str_contains($value, 'outofstock')) {
            return 'out_of_stock';
        }

        if (str_contains($value, 'instock')) {
            return 'in_stock';
        }

        if (str_contains($value, 'preorder')
            || str_contains($value, 'backorder')) {
            return 'on_order';
        }

        return null;
    }

    private function availabilityLabel(string $text): ?string
    {
        $matches = [];

        foreach ([
            'Produkt niedostępny',
            'Chwilowo niedostępny',
            'Produkt dostępny',
            'Produkt na zamówienie',
            'Na zamówienie',
        ] as $label) {
            if (! $this->containsComparable($text, $label)) {
                continue;
            }

            $matches[$this->availability($label)] = $label;
        }

        if (count($matches) !== 1) {
            return null;
        }

        return array_values($matches)[0];
    }

    private function availability(?string $label): string
    {
        if ($label === null) {
            return 'unknown';
        }

        $value = Str::lower(Str::ascii($label));

        if (str_contains($value, 'zamow')) {
            return 'on_order';
        }

        if (str_contains($value, 'niedostep')) {
            return 'out_of_stock';
        }

        if (str_contains($value, 'dostep')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    private function shippingTime(string $text): ?string
    {
        if (preg_match('/Przewidywany\\s+czas\\s+realizacji\\s+([^.;]{1,80})/iu', $text, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private function unitFromPriceText(string $text): ?string
    {
        if (preg_match('/\\([0-9 ,.]++\\s*zł\\s*\\/\\s*1\\s*([^\\)]+)\\)/iu', $text, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /** @return list<array{label:string,value:string}> */
    private function featureAttributes(Crawler $crawler, string $bodyText): array
    {
        $pairs = [];

        try {
            $crawler->filter('.product-features tr, #product-details tr, [class*="feature"] tr')->each(
                function (Crawler $row) use (&$pairs): void {
                    $cells = $row->filter('th,td');
                    if ($cells->count() >= 2) {
                        $this->addPair($pairs, $cells->eq(0)->text(''), $cells->eq(1)->text(''));
                    }
                }
            );
        } catch (Throwable) {
        }

        try {
            $crawler->filter('.product-features dl, #product-details dl')->each(function (Crawler $dl) use (&$pairs): void {
                $terms = $dl->filter('dt');
                $values = $dl->filter('dd');
                $count = min($terms->count(), $values->count());
                for ($i = 0; $i < $count; $i++) {
                    $this->addPair($pairs, $terms->eq($i)->text(''), $values->eq($i)->text(''));
                }
            });
        } catch (Throwable) {
        }

        foreach ([
            'Indeks',
            'ean13',
            'EAN',
            'Wyrób medyczny',
            'Klasa wyrobu medycznego',
            'Grupa docelowa',
            'Producent',
            'Adres producenta',
            'Adres email producenta',
            'Telefon producenta',
            'Rodzaj',
            'Ucisk',
            'Kolor',
            'Pojemność',
        ] as $label) {
            if ($this->attributeValue($pairs, $label) !== null) {
                continue;
            }

            $quoted = preg_quote($label, '/');
            if (preg_match('/(?:^|\\s)'.$quoted.'\\s+(.{1,180}?)(?=\\s+(?:Indeks|ean13|EAN|Wyrób medyczny|Klasa wyrobu medycznego|Grupa docelowa|Producent|Podmiot prowadzący reklamę|Adres producenta|Adres email producenta|Telefon producenta|Rodzaj|Ucisk|Kolor|Pojemność|Opinie|Pytania do produktu)(?:\\s|$)|$)/iu', $bodyText, $m) === 1) {
                $this->addPair($pairs, $label, $m[1]);
            }
        }

        return array_values($pairs);
    }

    /** @param array<string,array{label:string,value:string}> $pairs */
    private function addPair(array &$pairs, string $label, string $value): void
    {
        $label = trim($this->normalizeText($label), " \t\n\r\0\x0B:");
        $value = trim($this->normalizeText($value), " \t\n\r\0\x0B:");
        if ($label === '' || $value === '' || mb_strlen($label) > 100 || mb_strlen($value) > 500) {
            return;
        }

        $pairs[Str::lower(Str::ascii($label)).'|'.Str::lower(Str::ascii($value))] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    /** @param list<array{label:string,value:string}> $attributes */
    private function attributeValue(array $attributes, string $label): ?string
    {
        $needle = Str::lower(Str::ascii($label));
        foreach ($attributes as $attribute) {
            if (Str::lower(Str::ascii($attribute['label'])) === $needle) {
                return $attribute['value'];
            }
        }
        return null;
    }

    private function brand(Crawler $crawler, array $attributes, string $bodyText): ?string
    {
        $brand = $this->attributeValue($attributes, 'Marka');
        if ($brand !== null) {
            return $brand;
        }

        try {
            $node = $crawler->filter('[itemprop="brand"], .product-manufacturer, .brand')->first();
            if ($node->count() > 0) {
                $value = $this->normalizeText($node->text(''));
                $value = preg_replace('/^Marka\\s*:\\s*/iu', '', $value) ?? $value;
                if ($value !== '') {
                    return trim($value);
                }
            }
        } catch (Throwable) {
        }

        if (preg_match('/(?:^|\\s)Marka:\\s*([^|]{1,100}?)(?=\\s{2,}|Cena|$)/iu', $bodyText, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Resolve authoritative Seni24 combinations from schema.org ProductGroup
     * structured data. One product page may contain all concrete combinations.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private function structuredVariantCandidates(
        Crawler $crawler,
        string $externalProductId,
        string $selectedVariantId,
        array $groups,
        ?int $vatRate,
    ): array {
        $candidates = [];

        try {
            $crawler->filter('script[type="application/ld+json"]')->each(
                function (Crawler $script) use (
                    &$candidates,
                    $externalProductId,
                    $groups,
                    $vatRate,
                ): void {
                    $node = $script->getNode(0);

                    if (! $node instanceof DOMElement) {
                        return;
                    }

                    $json = trim((string) $node->textContent);

                    if ($json === '') {
                        return;
                    }

                    try {
                        $decoded = json_decode(
                            $json,
                            true,
                            512,
                            JSON_THROW_ON_ERROR,
                        );
                    } catch (Throwable) {
                        return;
                    }

                    $this->collectStructuredVariantCandidates(
                        $decoded,
                        $externalProductId,
                        $groups,
                        $vatRate,
                        $candidates,
                    );
                }
            );
        } catch (Throwable) {
        }

        if (isset($candidates[$selectedVariantId])) {
            $selected = $candidates[$selectedVariantId];
            unset($candidates[$selectedVariantId]);

            return [
                $selected,
                ...array_values($candidates),
            ];
        }

        return array_values($candidates);
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @param array<string,array<string,mixed>> $candidates
     */
    private function collectStructuredVariantCandidates(
        mixed $node,
        string $externalProductId,
        array $groups,
        ?int $vatRate,
        array &$candidates,
    ): void {
        if (! is_array($node)) {
            return;
        }

        $url = is_string($node['url'] ?? null)
            ? $this->normalizeText($node['url'])
            : null;

        $variantId = $url !== null
            ? $this->variantIdFromStructuredUrl($url, $externalProductId)
            : null;

        if ($variantId !== null) {
            $attributes = $this->structuredVariantAttributes($node, $groups);
            $offer = $this->structuredOffer($node['offers'] ?? null);
            $price = $offer !== null
                ? $this->structuredOfferPrice($offer)
                : null;

            $sku = is_string($node['sku'] ?? null)
                ? $this->normalizeText($node['sku'])
                : null;

            $ean = null;

            foreach (['gtin13', 'gtin14', 'gtin'] as $key) {
                if (is_string($node[$key] ?? null)) {
                    $ean = $this->normalizeText($node[$key]);

                    if ($ean !== '') {
                        break;
                    }
                }
            }

            if ($ean === '') {
                $ean = null;
            }

            if ($price !== null
                && ($attributes !== [] || $sku !== null || $ean !== null)) {
                $availability = $offer !== null
                    ? ($this->availabilityFromMachineValue(
                        (string) ($offer['availability'] ?? '')
                    ) ?? 'unknown')
                    : 'unknown';

                $label = $attributes === []
                    ? ($sku ?: $variantId)
                    : implode(', ', array_map(
                        static fn (array $attribute): string =>
                            $attribute['label'].': '.$attribute['value'],
                        $attributes,
                    ));

                $candidates[$variantId] = [
                    'external_variant_id' => $variantId,
                    'label' => $label,
                    'attributes' => $attributes,
                    'catalogue_number' => $sku,
                    'ean' => $ean,
                    'price_gross_amount' => $price,
                    'currency' => is_string($offer['priceCurrency'] ?? null)
                        ? strtoupper($this->normalizeText($offer['priceCurrency']))
                        : 'PLN',
                    'vat_rate' => $vatRate,
                    'availability' => $availability,
                    'availability_label' => match ($availability) {
                        'in_stock' => 'Produkt dostępny',
                        'out_of_stock' => 'Produkt niedostępny',
                        'on_order' => 'Produkt na zamówienie',
                        default => null,
                    },
                    'source_url' => $url,
                ];
            }
        }

        foreach ($node as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->collectStructuredVariantCandidates(
                $child,
                $externalProductId,
                $groups,
                $vatRate,
                $candidates,
            );
        }
    }

    private function variantIdFromStructuredUrl(
        string $url,
        string $externalProductId,
    ): ?string {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match(
            '/_'.preg_quote($externalProductId, '/').'-([0-9]+)(?:\.html)?$/u',
            $path,
            $matches,
        ) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array<string,mixed> $node
     * @param list<array<string,mixed>> $groups
     * @return list<array{label:string,value:string}>
     */
    private function structuredVariantAttributes(
        array $node,
        array $groups,
    ): array {
        $scalarValues = [];

        foreach ($node as $key => $value) {
            if (! is_string($key)
                || ! is_scalar($value)
                || in_array($key, [
                    '@context',
                    '@type',
                    '@id',
                    'url',
                    'sku',
                    'gtin',
                    'gtin13',
                    'gtin14',
                    'name',
                    'description',
                    'productID',
                    'mpn',
                ], true)) {
                continue;
            }

            $value = $this->normalizeText((string) $value);

            if ($value !== '') {
                $scalarValues[] = $value;
            }
        }

        $attributes = [];

        foreach ($groups as $group) {
            $label = is_string($group['label'] ?? null)
                ? $this->normalizeText($group['label'])
                : '';

            if ($label === '') {
                continue;
            }

            foreach (($group['options'] ?? []) as $option) {
                if (! is_array($option)
                    || ! is_string($option['value'] ?? null)) {
                    continue;
                }

                $optionValue = $this->normalizeText($option['value']);

                foreach ($scalarValues as $scalarValue) {
                    if (! $this->sameComparable(
                        $optionValue,
                        $scalarValue,
                    )) {
                        continue;
                    }

                    $attributes[$label] = [
                        'label' => $label,
                        'value' => $optionValue,
                    ];

                    break 2;
                }
            }
        }

        foreach ([
            'size' => 'Rozmiar',
            'color' => 'Kolor',
            'colour' => 'Kolor',
            'capacity' => 'Pojemność',
        ] as $key => $label) {
            if (isset($attributes[$label])
                || ! is_scalar($node[$key] ?? null)) {
                continue;
            }

            $value = $this->normalizeText((string) $node[$key]);

            if ($value !== '') {
                $attributes[$label] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        return array_values($attributes);
    }

    /** @return array<string,mixed>|null */
    private function structuredOffer(mixed $offers): ?array
    {
        if (! is_array($offers)) {
            return null;
        }

        if (array_key_exists('price', $offers)) {
            return $offers;
        }

        foreach ($offers as $offer) {
            if (is_array($offer) && array_key_exists('price', $offer)) {
                return $offer;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $offer */
    private function structuredOfferPrice(array $offer): ?float
    {
        $price = $offer['price'] ?? null;

        if (! is_string($price) && ! is_int($price) && ! is_float($price)) {
            return null;
        }

        $value = $this->money((string) $price);

        return $value >= 0 ? $value : null;
    }

    /**
     * Preserve authoritative DOM attributes that structured data omits.
     *
     * Selected DOM attributes apply to the selected combination. Options whose
     * group has exactly one possible value apply to every combination.
     *
     * @param list<array<string,mixed>> $candidates
     * @param list<array{label:string,value:string}> $selectedAttributes
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private function enrichStructuredVariantAttributes(
        array $candidates,
        string $selectedVariantId,
        array $selectedAttributes,
        array $groups,
    ): array {
        $contradictedSingleOptionKeys =
            $this->contradictedStructuredSingleOptionGroupKeys(
                $candidates,
                $groups,
            );

        foreach ($candidates as &$candidate) {
            $attributes = [];

            foreach (($candidate['attributes'] ?? []) as $attribute) {
                if (! is_array($attribute)
                    || ! is_string($attribute['label'] ?? null)
                    || ! is_string($attribute['value'] ?? null)) {
                    continue;
                }

                $attributes[
                    Str::lower(Str::ascii($attribute['label']))
                ] = [
                    'label' => $attribute['label'],
                    'value' => $attribute['value'],
                ];
            }

            if (($candidate['external_variant_id'] ?? null) === $selectedVariantId) {
                foreach ($selectedAttributes as $attribute) {
                    $fragmentKey = $this->variantFragmentKey(
                        $attribute['label'],
                    );

                    if (isset(
                        $contradictedSingleOptionKeys[$fragmentKey]
                    )) {
                        continue;
                    }

                    $key = Str::lower(
                        Str::ascii($attribute['label'])
                    );

                    $attributes[$key] ??= $attribute;
                }
            }

            foreach ($groups as $group) {
                $label = is_string($group['label'] ?? null)
                    ? $this->normalizeText($group['label'])
                    : '';

                $options = is_array($group['options'] ?? null)
                    ? $group['options']
                    : [];

                if ($label === '' || count($options) !== 1) {
                    continue;
                }

                if (isset(
                    $contradictedSingleOptionKeys[
                        $this->variantFragmentKey($label)
                    ]
                )) {
                    continue;
                }

                $option = $options[0];

                if (! is_array($option)
                    || ! is_string($option['value'] ?? null)) {
                    continue;
                }

                $value = $this->normalizeText($option['value']);

                if ($value === '') {
                    continue;
                }

                $key = Str::lower(Str::ascii($label));

                $attributes[$key] ??= [
                    'label' => $label,
                    'value' => $value,
                ];
            }

            $candidate['attributes'] = array_values($attributes);

            if ($candidate['attributes'] !== []) {
                $candidate['label'] = implode(', ', array_map(
                    static fn (array $attribute): string =>
                        $attribute['label'].': '.$attribute['value'],
                    $candidate['attributes'],
                ));
            }
        }

        unset($candidate);

        return $candidates;
    }

    /**
     * Return one-option DOM groups that cannot safely be treated as
     * product-wide invariants.
     *
     * Candidate URL fragments are used only as contradiction evidence.
     * Their values are not converted into customer-facing attributes here.
     *
     * A group is unsafe when:
     * - candidate URLs expose more than one value for the group, or
     * - only part of the structured candidate set exposes that group.
     *
     * @param list<array<string,mixed>> $candidates
     * @param list<array<string,mixed>> $groups
     * @return array<string,true>
     */
    private function contradictedStructuredSingleOptionGroupKeys(
        array $candidates,
        array $groups,
    ): array {
        if (count($candidates) < 2) {
            return [];
        }

        $contradicted = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $label = is_string($group['label'] ?? null)
                ? $this->normalizeText($group['label'])
                : '';

            $options = is_array($group['options'] ?? null)
                ? $group['options']
                : [];

            if ($label === '' || count($options) !== 1) {
                continue;
            }

            $key = $this->variantFragmentKey($label);

            if ($key === '') {
                continue;
            }

            $values = [];
            $candidateCountWithValue = 0;

            foreach ($candidates as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $sourceUrl = is_string(
                    $candidate['source_url'] ?? null
                )
                    ? $candidate['source_url']
                    : null;

                $fragmentValues = $this->variantFragmentMap(
                    $sourceUrl,
                );

                if (! isset($fragmentValues[$key])) {
                    continue;
                }

                $candidateCountWithValue++;
                $values[$fragmentValues[$key]] = true;
            }

            if (
                count($values) > 1
                || (
                    $candidateCountWithValue > 0
                    && $candidateCountWithValue < count($candidates)
                )
            ) {
                $contradicted[$key] = true;
            }
        }

        return $contradicted;
    }

    /**
     * @return array<string,string>
     */
    private function variantFragmentMap(?string $url): array
    {
        if ($url === null || trim($url) === '') {
            return [];
        }

        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        if (! is_string($fragment) || trim($fragment) === '') {
            return [];
        }

        $result = [];

        foreach (
            explode('/', trim(rawurldecode($fragment), '/'))
            as $segment
        ) {
            if (! str_contains($segment, '-')) {
                continue;
            }

            [$rawKey, $rawValue] = explode('-', $segment, 2);

            $key = $this->variantFragmentKey($rawKey);
            $value = $this->variantFragmentKey($rawValue);

            if ($key === '' || $value === '') {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function variantFragmentKey(string $value): string
    {
        $value = Str::lower(
            Str::ascii(
                $this->normalizeText(
                    rawurldecode($value),
                ),
            ),
        );

        return trim(
            preg_replace(
                '/[^a-z0-9]+/',
                '_',
                $value,
            ) ?? $value,
            '_',
        );
    }

    /**
     * Structured variants must be distinguishable by the customer-facing
     * attributes that we actually imported.
     *
     * Catalogue number, EAN and external ID deliberately do not participate:
     * they prove source identity but do not repair missing semantic choices.
     *
     * @param list<array<string,mixed>> $candidates
     */
    private function hasDuplicateStructuredVariantAttributeSignatures(
        array $candidates,
    ): bool {
        if (count($candidates) < 2) {
            return false;
        }

        $seen = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $parts = [];

            foreach (($candidate['attributes'] ?? []) as $attribute) {
                if (! is_array($attribute)
                    || ! is_string($attribute['label'] ?? null)
                    || ! is_string($attribute['value'] ?? null)) {
                    continue;
                }

                $label = $this->variantFragmentKey(
                    $attribute['label'],
                );

                $value = $this->variantFragmentKey(
                    $attribute['value'],
                );

                if ($label === '' || $value === '') {
                    continue;
                }

                $parts[] = $label.'='.$value;
            }

            sort($parts, SORT_STRING);

            $signature = implode('|', $parts);

            if (isset($seen[$signature])) {
                return true;
            }

            $seen[$signature] = true;
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param list<array<string,mixed>> $groups
     */
    private function structuredVariantsCoverOptions(
        array $candidates,
        array $groups,
    ): bool {
        if ($candidates === []) {
            return false;
        }

        if ($groups === []) {
            return true;
        }

        foreach ($candidates as $candidate) {
            foreach ($groups as $group) {
                $label = is_string($group['label'] ?? null)
                    ? $this->normalizeText($group['label'])
                    : '';

                if ($label === '') {
                    continue;
                }

                $hasAttribute = false;

                foreach (($candidate['attributes'] ?? []) as $attribute) {
                    if (is_array($attribute)
                        && is_string($attribute['label'] ?? null)
                        && $this->sameComparable(
                            $attribute['label'],
                            $label,
                        )) {
                        $hasAttribute = true;
                        break;
                    }
                }

                if (! $hasAttribute) {
                    return false;
                }
            }
        }

        foreach ($groups as $group) {
            $label = is_string($group['label'] ?? null)
                ? $this->normalizeText($group['label'])
                : '';

            foreach (($group['options'] ?? []) as $option) {
                if ($label === ''
                    || ! is_array($option)
                    || ! is_string($option['value'] ?? null)) {
                    continue;
                }

                $optionValue = $this->normalizeText($option['value']);

                /*
                 * Seni24 sometimes renders a literal "0" as the DOM value
                 * for an option whose real customer-facing semantic is
                 * available from structured ProductGroup data.
                 *
                 * A placeholder is not an option that structured
                 * candidates should be required to cover.
                 */
                if ($this->isVariantPlaceholderValue($optionValue)) {
                    continue;
                }

                $covered = false;

                foreach ($candidates as $candidate) {
                    foreach (($candidate['attributes'] ?? []) as $attribute) {
                        if (! is_array($attribute)
                            || ! is_string($attribute['label'] ?? null)
                            || ! is_string($attribute['value'] ?? null)) {
                            continue;
                        }

                        if ($this->sameComparable(
                            $attribute['label'],
                            $label,
                        ) && $this->sameComparable(
                            $attribute['value'],
                            $optionValue,
                        )) {
                            $covered = true;
                            break 2;
                        }
                    }
                }

                if (! $covered) {
                    return false;
                }
            }
        }

        return true;
    }

    private function sameComparable(string $left, string $right): bool
    {
        $normalize = fn (string $value): string => Str::lower(
            Str::ascii($this->normalizeText($value))
        );

        return $normalize($left) === $normalize($right);
    }

    /** @return array{groups:list<array<string,mixed>>,selected_attributes:list<array{label:string,value:string}>,unresolved:bool} */
    private function variantData(Crawler $crawler): array
    {
        $groups = [];
        $selected = [];
        $unresolved = false;

        $selectors = ['.product-variants-item', '.product-variants fieldset'];

        foreach ($selectors as $selector) {
            try {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() === 0) {
                    continue;
                }

                $nodes->each(function (Crawler $groupNode) use (&$groups, &$selected, &$unresolved): void {
                    $label = $this->firstText($groupNode, ['.control-label', 'legend', '.label']);
                    $label = trim(preg_replace('/[:\\s]+$/u', '', $label) ?? $label);
                    if ($label === '') {
                        return;
                    }

                    $options = [];

                    try {
                        $groupNode->filter('select option')->each(function (Crawler $option) use (&$options, &$selected, $label): void {
                            $value = $this->normalizeText($option->text(''));
                            if ($value === '') {
                                return;
                            }
                            $isSelected = $option->attr('selected') !== null;
                            $options[] = ['value' => $value, 'selected' => $isSelected];
                            if ($isSelected) {
                                $selected[] = ['label' => $label, 'value' => $value];
                            }
                        });
                    } catch (Throwable) {
                    }

                    try {
                        $groupNode->filter('input[data-product-attribute], input[name^="group["]')->each(
                            function (Crawler $input) use (&$options, &$selected, $label): void {
                                $value = $this->inputOptionLabel($input);
                                if ($value === '') {
                                    return;
                                }
                                $isSelected = $input->attr('checked') !== null;
                                $options[] = ['value' => $value, 'selected' => $isSelected];
                                if ($isSelected) {
                                    $selected[] = ['label' => $label, 'value' => $value];
                                }
                            }
                        );
                    } catch (Throwable) {
                    }

                    $deduped = [];
                    foreach ($options as $option) {
                        $value = $option['value'];

                        if (! isset($deduped[$value])) {
                            $deduped[$value] = $option;
                            continue;
                        }

                        if ($option['selected']) {
                            $deduped[$value]['selected'] = true;
                        }
                    }
                    $options = array_values($deduped);

                    if (count($options) > 1) {
                        $unresolved = true;
                    }

                    if ($options !== []) {
                        $groups[] = ['label' => $label, 'options' => $options];
                    }
                });

                if ($groups !== []) {
                    break;
                }
            } catch (Throwable) {
            }
        }

        $selectedDeduped = [];
        foreach ($selected as $attribute) {
            $selectedDeduped[$attribute['label'].'|'.$attribute['value']] = $attribute;
        }

        return [
            'groups' => $groups,
            'selected_attributes' => array_values($selectedDeduped),
            'unresolved' => $unresolved,
        ];
    }

    private function inputOptionLabel(Crawler $input): string
    {
        foreach (['title', 'aria-label'] as $attribute) {
            $value = $this->normalizeText((string) $input->attr($attribute));
            if ($value !== '') {
                return $value;
            }
        }

        $node = $input->getNode(0);

        if ($node instanceof DOMElement) {
            $parent = $node->parentElement;

            if ($parent instanceof DOMElement && mb_strtolower($parent->tagName) === 'label') {
                $value = $this->normalizeText($parent->textContent ?? '');

                if ($value !== '') {
                    return $value;
                }
            }

            $id = trim($node->getAttribute('id'));

            if ($id !== '' && $node->ownerDocument !== null) {
                foreach ($node->ownerDocument->getElementsByTagName('label') as $label) {
                    if (! $label instanceof DOMElement || $label->getAttribute('for') !== $id) {
                        continue;
                    }

                    $value = $this->normalizeText($label->textContent ?? '');

                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        foreach (['data-value', 'value'] as $attribute) {
            $value = $this->normalizeText((string) $input->attr($attribute));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @return list<array{url:string,alt:string}> */
    private function images(Crawler $crawler, string $baseUrl): array
    {
        $images = [];
        $selectors = [
            '.product-cover img',
            '.product-images img',
            '.images-container img',
            '.thumb-container img',
            '.js-qv-product-cover',
            '.js-thumb',
        ];

        foreach ($selectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $baseUrl): void {
                    $url = null;
                    foreach (['data-image-large-src', 'data-zoom-image', 'data-src', 'src'] as $attribute) {
                        $candidate = $node->attr($attribute);
                        if (is_string($candidate) && trim($candidate) !== '') {
                            $url = $this->absoluteImageUrl($candidate, $baseUrl);
                            if ($url !== null) {
                                break;
                            }
                        }
                    }

                    if ($url === null || isset($images[$url])) {
                        return;
                    }

                    $images[$url] = [
                        'url' => $url,
                        'alt' => $this->normalizeText((string) $node->attr('alt')),
                    ];
                });
            } catch (Throwable) {
            }
        }

        return array_values($images);
    }

    private function absoluteImageUrl(string $url, string $baseUrl): ?string
    {
        $absolute = $this->absoluteUrl($url, $baseUrl);
        if ($absolute === null) {
            return null;
        }

        $parts = parse_url($absolute);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        if (!str_ends_with($host, 'seni24.pl')) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return null;
        }

        return 'https://'.$host.$path;
    }

    private function descriptionHtml(Crawler $crawler): ?string
    {
        foreach ([
            '#description .product-description',
            '#description',
            '.product-description',
            '[itemprop="description"]',
        ] as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
                if ($node->count() > 0) {
                    $html = trim((string) $node->html());
                    if ($html !== '' && mb_strlen($this->normalizeText(strip_tags($html))) >= 20) {
                        return $html;
                    }
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function metaContent(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                $value = $this->normalizeText((string) $node->attr('content'));
                return $value !== '' ? $value : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /** @param list<string> $selectors */
    private function firstText(Crawler $crawler, array $selectors): string
    {
        foreach ($selectors as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
                if ($node->count() > 0) {
                    $text = $this->normalizeText($node->text(''));
                    if ($text !== '') {
                        return $text;
                    }
                }
            } catch (Throwable) {
            }
        }
        return '';
    }

    private function absoluteUrl(string $url, ?string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(?:mailto|tel|javascript):/iu', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (preg_match('#^https?://#iu', $url) === 1) {
            return preg_replace('#^http://#i', 'https://', $url) ?? $url;
        }
        if (str_starts_with($url, '/')) {
            return 'https://www.seni24.pl'.$url;
        }
        if ($baseUrl === null) {
            return null;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return 'https://www.seni24.pl'.($dir === '' || $dir === '.' ? '' : $dir).'/'.ltrim($url, '/');
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function containsComparable(string $haystack, string $needle): bool
    {
        $normalize = static fn (string $value): string => Str::lower(Str::ascii($value));
        return str_contains($normalize($haystack), $normalize($needle));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }
        return array_values(array_unique($result));
    }

    /** @param array<string,mixed>|null $context
     *  @return array<string,mixed>
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
            'availability_label' => null,
            'is_on_order' => false,
            'shipping_time' => null,
            'unit' => null,
            'catalogue_number' => null,
            'ean' => null,
            'source_category_path' => [],
            'categories' => [],
            'description_html' => null,
            'description_plain' => '',
            'images' => [],
            'attributes' => [],
            'variant_candidates' => [],
            'variant_options' => [],
            'variants_unresolved' => false,
            'is_medical_device' => false,
            'medical_device_class' => null,
            'raw_context' => is_array($context) ? $context : [],
            'warnings' => [],
            'failed_urls' => [$url => $reason],
        ];
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.5',
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
