<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class QmedProductDataCrawler
{
    private int $timeout = 20;

    private int $delayMs = 500;

    public function withTimeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->delayMs = max(0, $milliseconds);

        return $this;
    }

    /** @param array<string, mixed> $discovery
     * @return array<string, mixed>
     */
    public function crawl(array $discovery, ?int $limit = null, int $offset = 0): array
    {
        if (($discovery['source'] ?? null) !== 'qmed' || ! is_array($discovery['products'] ?? null)) {
            throw new InvalidArgumentException('Expected Qmed product discovery JSON.');
        }

        $input = array_slice($discovery['products'], $offset, $limit);
        $products = [];
        $failed = [];
        $warnings = [];

        foreach ($input as $index => $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : null;
            if (! is_string($url) || ! $this->isProductUrl($url)) {
                $failed['entry-'.$index] = 'Invalid Qmed product URL';

                continue;
            }

            try {
                if ($this->delayMs > 0) {
                    usleep($this->delayMs * 1000);
                }

                $response = Http::timeout($this->timeout)
                    ->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'KonjiShop-QmedCatalogueResearch/1.0'])
                    ->get($url);

                if (! $response->successful()) {
                    throw new RuntimeException('HTTP '.$response->status());
                }

                $product = $this->parseProduct($response->body(), $url);
                $product['categories'] = is_array($entry['categories'] ?? null) ? $entry['categories'] : [];
                $products[] = $product;

                if ($product['technical_tables'] !== [] || $product['attachments'] !== []) {
                    $warnings[$url] = 'Sizing / documents need manual review before variants or downloads.';
                }
            } catch (Throwable $exception) {
                $failed[$url] = $exception->getMessage();
            }
        }

        return [
            'source' => 'qmed',
            'source_product_url_count' => count($input),
            'product_count' => count($products),
            'products' => $products,
            'failed_urls' => $failed,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, mixed> */
    public function parseProduct(string $html, string $sourceUrl): array
    {
        if (! $this->isProductUrl($sourceUrl)) {
            throw new InvalidArgumentException('URL must be a Qmed catalogue product URL.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadHTML('<?xml encoding="UTF-8">'.$html)) {
                throw new RuntimeException('Invalid Qmed HTML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $title = $this->first($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " itemTitle ")]');
        $body = $this->first($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " itemFullText ")]');
        if (! $title || ! $body || $this->cleanText($title->textContent) === '') {
            throw new RuntimeException('Qmed product title or content missing.');
        }

        $sections = [];
        $sectionName = '_intro';
        $sections[$sectionName] = '';
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'h4') {
                $sectionName = $this->cleanText($child->textContent);
                $sections[$sectionName] ??= '';

                continue;
            }
            $sections[$sectionName] .= $document->saveHTML($child);
        }

        $tables = [];
        foreach ($xpath->query('.//table', $body) ?: [] as $table) {
            $rows = [];
            foreach ($xpath->query('.//tr', $table) ?: [] as $tr) {
                $cells = [];
                foreach ($xpath->query('./th|./td', $tr) ?: [] as $cell) {
                    $cells[] = $this->cleanText($cell->textContent);
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }
            $tables[] = ['rows' => $rows, 'html' => $document->saveHTML($table)];
        }

        $images = [];
        $main = $this->first($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " itemImage ")]//img[@src]');
        if ($main instanceof DOMElement) {
            $url = $this->mediaUrl($main->getAttribute('src'));
            if ($url !== null) {
                $images[$url] = ['url' => $url, 'role' => 'main'];
            }
        }
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " itemImageGallery ")]//a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = $this->mediaUrl($link->getAttribute('href'));
            if ($url !== null && preg_match('~\.(?:jpe?g|png|webp|gif)(?:\?|$)~i', $url)) {
                $images[$url] ??= ['url' => $url, 'role' => 'gallery'];
            }
        }

        $attachments = [];
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " itemAttachmentsBlock ")]//form[contains(concat(" ",normalize-space(@class)," ")," jfile ")]') ?: [] as $form) {
            $fields = [];
            foreach ($xpath->query('.//input[@type="hidden"][@name]', $form) ?: [] as $input) {
                if ($input instanceof DOMElement) {
                    $fields[$input->getAttribute('name')] = $input->getAttribute('value');
                }
            }
            if (($fields['option'] ?? '') !== 'com_ajax'
                || ($fields['plugin'] ?? '') !== 'jmpattachments'
                || ! ctype_digit((string) ($fields['id'] ?? ''))) {
                continue;
            }
            $label = $this->first($xpath, './/a[contains(concat(" ",normalize-space(@class)," ")," __title ")]', $form);
            $attachments[] = [
                'label' => $label ? $this->cleanText($label->textContent) : '',
                'source_form_action' => $this->mediaUrl($form instanceof DOMElement ? $form->getAttribute('action') : ''),
                'request_method' => 'POST',
                'request_fields' => $fields,
                'download_url' => null,
                'downloaded' => false,
            ];
        }

        $raw = $document->saveHTML($body);

        return [
            'source' => 'qmed',
            'source_url' => $sourceUrl,
            'name' => $this->cleanText($title->textContent),
            'description_html' => $raw,
            'description_sections_html' => $sections,
            'technical_tables' => $tables,
            'images' => array_values($images),
            'attachments' => $attachments,
            'external_id' => 'qmed-'.hash(
                'sha256',
                'https://qmedinfo.pl'.rtrim(
                    (string) parse_url($sourceUrl, PHP_URL_PATH),
                    '/'
                ),
            ),
            'sku' => null,
            'price_gross_amount' => null,
            'variant_candidates' => [],
            'variants_unresolved' => true,
            'status' => 'draft',
        ];
    }

    private function first(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMNode
    {
        $nodes = $xpath->query($expression, $context);

        return $nodes !== false && $nodes->length > 0 ? $nodes->item(0) : null;
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text)) ?? $text);
    }

    private function isProductUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== QmedProductUrlScraper::HOST
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return preg_match('~^/pl/oferta/(?:[^/?#]+/){1,3}[^/?#]+$~', (string) ($parts['path'] ?? '')) === 1
            && ! isset($parts['query']);
    }

    private function mediaUrl(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
        if ($value === '' || str_starts_with($value, '//')) {
            return null;
        }
        if (str_starts_with($value, '/')) {
            $value = 'https://'.QmedProductUrlScraper::HOST.$value;
        }
        $parts = parse_url($value);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== QmedProductUrlScraper::HOST) {
            return null;
        }

        return $value;
    }
}
