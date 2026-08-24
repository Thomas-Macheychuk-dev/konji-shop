<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use DOMElement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class SigvarisCombinationEnumerator extends SigvarisHttpClient
{
    /**
     * Enumerate only combinations returned by the source PrestaShop refresh endpoint.
     *
     * @return array<string,mixed>|null
     */
    public function enumerate(string $url, int $maxRequests = 1000): ?array
    {
        $failed = [];
        $html = $this->fetchBody($url, $failed);
        if ($html === null) {
            return null;
        }

        return $this->enumerateFromHtml($html, $url, $maxRequests);
    }

    /** @return array<string,mixed> */
    public function enumerateFromHtml(string $html, string $sourceUrl, int $maxRequests = 1000): array
    {
        $sourceUrl = $this->normalizeSiteUrl($sourceUrl, 'https://'.self::CANONICAL_HOST.'/') ?? $sourceUrl;
        $crawler = new Crawler($html, $sourceUrl);
        $identity = $this->productIdentity($sourceUrl);
        $name = $this->firstText($crawler, ['h1', '.product-name', '[itemprop="name"]']);
        $state = $this->variantState($crawler);
        $hidden = $this->hiddenProductFields($crawler, $identity['product_id']);

        $warnings = [];
        if ($state['groups'] === []) {
            return [
                'source' => 'sigvaris',
                'platform' => 'prestashop',
                'source_url' => $sourceUrl,
                'external_product_id' => $identity['product_id'],
                'name' => $name,
                'default_combination_id' => $identity['combination_id'],
                'selector_groups' => [],
                'candidate_state_count' => 0,
                'refresh_request_count' => 0,
                'combination_count' => $identity['combination_id'] !== null ? 1 : 0,
                'combinations' => $identity['combination_id'] !== null ? [[
                    'external_variant_id' => $identity['combination_id'],
                    'product_url' => $sourceUrl,
                    'reference' => $this->reference($crawler),
                    'availability' => $this->availability($crawler),
                    'stock_quantity' => $this->stockQuantity($crawler),
                    'display_price_amount' => $this->displayPrice($crawler),
                    'attributes' => [],
                ]] : [],
                'truncated' => false,
                'warnings' => [],
                'failed_requests' => [],
            ];
        }

        $queue = [];
        $queued = [];
        $requested = [];
        $combinations = [];
        $failedRequests = [];
        $requestCount = 0;
        $candidateStateCount = 0;
        $truncated = false;

        $enqueue = function (array $selection) use (&$queue, &$queued): void {
            ksort($selection, SORT_NUMERIC);
            $signature = $this->selectionSignature($selection);
            if ($signature === '' || isset($queued[$signature])) {
                return;
            }
            $queued[$signature] = true;
            $queue[] = $selection;
        };

        $initialSelection = $state['selection'];
        $enqueue($initialSelection);

        if ($identity['combination_id'] !== null) {
            $combinations[$identity['combination_id']] = $this->combinationRecord(
                $identity['combination_id'],
                $sourceUrl,
                $state,
                $crawler,
            );
        }

        while ($queue !== []) {
            /** @var array<string,string> $selection */
            $selection = array_shift($queue);
            $signature = $this->selectionSignature($selection);
            if (isset($requested[$signature])) {
                continue;
            }

            if ($requestCount >= max(1, $maxRequests)) {
                $truncated = true;
                $warnings[] = "Combination enumeration stopped at the max request limit ({$maxRequests}).";
                break;
            }

            $requested[$signature] = true;
            $candidateStateCount++;
            $requestCount++;

            $this->emit(sprintf(
                'REFRESH %s | selection=%s',
                $sourceUrl,
                $signature,
            ));

            $response = $this->refresh($sourceUrl, $hidden, $selection, $failedRequests);
            if ($response === null) {
                continue;
            }

            $combinationId = trim((string) ($response['id_product_attribute'] ?? ''));
            $productUrl = $this->normalizeSiteUrl((string) ($response['product_url'] ?? ''), $sourceUrl)
                ?? $sourceUrl;

            $fragmentHtml = '<div class="product-variants">'.($response['product_variants'] ?? '').'</div>'
                .'<div class="product-details">'.($response['product_details'] ?? '').'</div>'
                .'<div class="product-prices">'.($response['product_prices'] ?? '').'</div>'
                .'<div class="product-add-to-cart">'.($response['product_add_to_cart'] ?? '').'</div>';
            $fragmentCrawler = new Crawler($fragmentHtml, $productUrl);
            $nextState = $this->variantState($fragmentCrawler);

            if ($combinationId !== '' && $combinationId !== '0') {
                $combinations[$combinationId] = $this->combinationRecord(
                    $combinationId,
                    $productUrl,
                    $nextState,
                    $fragmentCrawler,
                );
            }

            foreach ($this->neighborSelections($nextState) as $neighbor) {
                $enqueue($neighbor);
            }
        }

        ksort($combinations, SORT_NATURAL);

        return [
            'source' => 'sigvaris',
            'platform' => 'prestashop',
            'source_url' => $sourceUrl,
            'external_product_id' => $identity['product_id'],
            'name' => $name,
            'default_combination_id' => $identity['combination_id'],
            'selector_groups' => $this->publicGroups($state),
            'candidate_state_count' => $candidateStateCount,
            'refresh_request_count' => $requestCount,
            'combination_count' => count($combinations),
            'combinations' => array_values($combinations),
            'truncated' => $truncated,
            'warnings' => array_values(array_unique($warnings)),
            'failed_requests' => $failedRequests,
        ];
    }

    /** @param array<string,string> $hidden @param array<string,string> $selection @param array<string,string> $failedRequests @return array<string,mixed>|null */
    private function refresh(string $sourceUrl, array $hidden, array $selection, array &$failedRequests): ?array
    {
        $query = $hidden;
        foreach ($selection as $groupId => $attributeId) {
            $query['group['.$groupId.']'] = $attributeId;
        }

        $separator = str_contains($sourceUrl, '?') ? '&' : '?';
        $refreshUrl = $sourceUrl.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->pause($this->requestDelayMilliseconds);

            try {
                /** @var Response $response */
                $response = Http::withHeaders([
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                    'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
                    'Cache-Control' => 'no-cache',
                    'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopSigvarisCrawler/1.0)',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $sourceUrl,
                ])->withOptions(['verify' => $this->verifyTls])
                    ->timeout($this->timeoutSeconds)
                    ->asForm()
                    ->post($refreshUrl, [
                        'ajax' => 1,
                        'action' => 'refresh',
                        'quantity_wanted' => 1,
                    ]);

                if ($response->successful()) {
                    $json = $response->json();
                    if (is_array($json)) {
                        return $json;
                    }
                    $lastError = 'Refresh endpoint did not return a JSON object.';
                    break;
                }

                $lastError = 'HTTP '.$response->status();
                if (! in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true)) {
                    break;
                }
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            if ($attempt < $this->attempts) {
                $this->pause($this->retryDelayMilliseconds);
            }
        }

        $failedRequests[$this->selectionSignature($selection)] = $lastError ?? 'Unknown refresh failure';

        return null;
    }

    /** @return array{groups:array<string,array<string,mixed>>,selection:array<string,string>} */
    private function variantState(Crawler $crawler): array
    {
        $groups = [];
        $selection = [];

        $crawler->filter('.product-variants .product-variants-item')->each(function (Crawler $item) use (&$groups, &$selection): void {
            $label = $this->firstText($item, ['.control-label', 'label', '.form-control-label']);
            if ($label === null) {
                return;
            }
            $label = rtrim($label, ': ');

            $control = $item->filter('[data-product-attribute], select[name^="group["], input[name^="group["]')->first();
            if ($control->count() === 0) {
                return;
            }

            $groupId = trim((string) $control->attr('data-product-attribute'));
            if ($groupId === '') {
                $name = (string) $control->attr('name');
                if (preg_match('/^group\[(\d+)\]$/', $name, $m) === 1) {
                    $groupId = $m[1];
                }
            }
            if ($groupId === '') {
                return;
            }

            $options = [];
            $selected = null;

            $item->filter('option')->each(function (Crawler $option) use (&$options, &$selected): void {
                $value = trim((string) $option->attr('value'));
                $label = $this->normalizeText($option->text(''));
                if ($value === '' || $label === '') {
                    return;
                }
                $record = ['external_attribute_id' => $value, 'label' => $label];
                $options[$value] = $record;
                $node = $option->getNode(0);
                if ($node instanceof DOMElement && $node->hasAttribute('selected')) {
                    $selected = $value;
                }
            });

            $item->filter('input')->each(function (Crawler $input) use (&$options, &$selected): void {
                $node = $input->getNode(0);
                if (! $node instanceof DOMElement) {
                    return;
                }
                $value = trim($node->getAttribute('value'));
                if ($value === '') {
                    return;
                }
                $id = $node->getAttribute('id');
                $label = '';
                if ($id !== '') {
                    $labelNode = $input->ancestors()->last()->filter('label[for="'.$id.'"]')->first();
                    if ($labelNode->count() > 0) {
                        $label = $this->normalizeText($labelNode->text(''));
                    }
                }
                $label = $label !== '' ? $label : $this->normalizeText($node->getAttribute('title'));
                $label = $label !== '' ? $label : $value;
                $options[$value] = ['external_attribute_id' => $value, 'label' => $label];
                if ($node->hasAttribute('checked')) {
                    $selected = $value;
                }
            });

            if ($selected === null && count($options) === 1) {
                $selected = (string) array_key_first($options);
            }

            if ($selected !== null) {
                $selection[$groupId] = $selected;
            }

            $groups[$groupId] = [
                'external_group_id' => $groupId,
                'label' => $label,
                'options' => array_values($options),
                'selected_external_attribute_id' => $selected,
            ];
        });

        ksort($groups, SORT_NUMERIC);
        ksort($selection, SORT_NUMERIC);

        return ['groups' => $groups, 'selection' => $selection];
    }

    /** @param array{groups:array<string,array<string,mixed>>,selection:array<string,string>} $state @return array<int,array<string,string>> */
    private function neighborSelections(array $state): array
    {
        $neighbors = [];
        $base = $state['selection'];
        if ($base === [] || count($base) !== count($state['groups'])) {
            return [];
        }

        foreach ($state['groups'] as $groupId => $group) {
            foreach ($group['options'] ?? [] as $option) {
                $attributeId = (string) ($option['external_attribute_id'] ?? '');
                if ($attributeId === '' || ($base[$groupId] ?? null) === $attributeId) {
                    continue;
                }
                $candidate = $base;
                $candidate[$groupId] = $attributeId;
                ksort($candidate, SORT_NUMERIC);
                $neighbors[] = $candidate;
            }
        }

        return $neighbors;
    }

    /** @param array{groups:array<string,array<string,mixed>>,selection:array<string,string>} $state @return array<int,array<string,mixed>> */
    private function publicGroups(array $state): array
    {
        return array_values($state['groups']);
    }

    /** @param array{groups:array<string,array<string,mixed>>,selection:array<string,string>} $state @return array<string,mixed> */
    private function combinationRecord(string $combinationId, string $productUrl, array $state, Crawler $crawler): array
    {
        $attributes = [];
        foreach ($state['groups'] as $groupId => $group) {
            $selectedId = $state['selection'][$groupId] ?? null;
            if ($selectedId === null) {
                continue;
            }
            $label = null;
            foreach ($group['options'] ?? [] as $option) {
                if ((string) ($option['external_attribute_id'] ?? '') === (string) $selectedId) {
                    $label = (string) ($option['label'] ?? $selectedId);
                    break;
                }
            }
            $attributes[] = [
                'external_group_id' => (string) $groupId,
                'label' => (string) ($group['label'] ?? $groupId),
                'external_attribute_id' => (string) $selectedId,
                'value' => $label ?? (string) $selectedId,
            ];
        }

        return [
            'external_variant_id' => $combinationId,
            'product_url' => $productUrl,
            'reference' => $this->reference($crawler),
            'display_price_amount' => $this->displayPrice($crawler),
            'availability' => $this->availability($crawler),
            'stock_quantity' => $this->stockQuantity($crawler),
            'attributes' => $attributes,
        ];
    }

    /** @return array<string,string> */
    private function hiddenProductFields(Crawler $crawler, string $productId): array
    {
        $fields = ['id_product' => $productId];
        $form = $crawler->filter('.product-actions form')->first();
        if ($form->count() === 0) {
            return $fields;
        }

        $form->filter('input[type="hidden"][name]')->each(function (Crawler $input) use (&$fields): void {
            $name = trim((string) $input->attr('name'));
            $value = trim((string) $input->attr('value'));
            if ($name === '' || str_starts_with($name, 'group[') || in_array($name, ['add', 'submitCustomizedData'], true)) {
                return;
            }
            $fields[$name] = $value;
        });

        $fields['id_product'] = $productId;

        return $fields;
    }

    /** @param array<string,string> $selection */
    private function selectionSignature(array $selection): string
    {
        ksort($selection, SORT_NUMERIC);
        return implode('|', array_map(
            static fn (string $groupId, string $attributeId): string => $groupId.'='.$attributeId,
            array_keys($selection),
            array_values($selection),
        ));
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

    private function reference(Crawler $crawler): ?string
    {
        foreach (['.product-reference span', '[itemprop="sku"]', '.product-reference', '.product-details'] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }
            $text = $this->normalizeText($node->text(''));
            if (preg_match('/(?:Indeks|Reference|SKU)\s*:?\s*([A-Za-z0-9._\/-]+?)(?=W\s+magazynie\b|\s|$)/ui', $text, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    private function stockQuantity(Crawler $crawler): ?int
    {
        $text = $this->normalizeText($crawler->text(''));
        if (preg_match('/W\s+magazynie\s+(\d+)\s+(?:Przedmioty|Przedmiotów|szt)/ui', $text, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    private function availability(Crawler $crawler): string
    {
        $quantity = $this->stockQuantity($crawler);
        if ($quantity !== null && $quantity > 0) {
            return 'in_stock';
        }
        $text = $this->normalizeText($crawler->text(''));
        if (preg_match('/dostępny\s+na\s+zamówienie/ui', $text) === 1) {
            return 'on_order';
        }
        if (preg_match('/brak\s+w\s+magazynie|niedostępny/ui', $text) === 1) {
            return 'out_of_stock';
        }
        return 'unknown';
    }

    private function displayPrice(Crawler $crawler): ?float
    {
        foreach (['.current-price .current-price-value', '.current-price [itemprop="price"]', '.product-prices [itemprop="price"]', '.product-prices .current-price', '[itemprop="price"]'] as $selector) {
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

    private function parseMoney(string $value): ?float
    {
        $value = str_replace(["\xc2\xa0", ' ', 'zł', 'PLN'], '', $value);
        $value = str_replace(',', '.', $value);
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m) !== 1) {
            return null;
        }
        return (float) $m[0];
    }
}
