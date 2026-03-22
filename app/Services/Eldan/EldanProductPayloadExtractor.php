<?php

namespace App\Services\Eldan;

class EldanProductPayloadExtractor
{
    public function extract(string $html): array
    {
        return [
            'product' => $this->extractProductPayload($html),
            'config' => $this->extractConfigPayload($html),
            'sections' => [
                'product_details_html' => $this->extractSectionHtml($html, 'Szczegóły produktu', ['Skład i Pielęgnacja']),
                'care_html' => $this->extractSectionHtml($html, 'Skład i Pielęgnacja', []),
                'size_table_html' => $this->extractSizeTableHtml($html),
            ],
        ];
    }

    private function extractSizeTableHtml(string $html): ?string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Limit search to the size-table modal area
        $buttonPos = mb_stripos($decoded, 'Tabela rozmiarów');

        if ($buttonPos === false) {
            return null;
        }

        $window = mb_substr($decoded, $buttonPos, 12000);

        if (! preg_match('/<table\b[^>]*>.*?<\/table>/is', $window, $tableMatch)) {
            return null;
        }

        $result = $tableMatch[0];

        // Optional note under the table
        if (preg_match('/<\/table>\s*(<p\b[^>]*>.*?<\/p>)/is', $window, $noteMatch)) {
            $result .= $noteMatch[1];
        }

        return trim($result);
    }

    private function extractProductPayload(string $html): ?array
    {
        $marker = '<v-product :product="';
        $start = strpos($html, $marker);

        if ($start === false) {
            return null;
        }

        $start += strlen($marker);
        $end = strpos($html, '"', $start);

        if ($end === false) {
            return null;
        }

        $raw = substr($html, $start, $end - $start);

        return $this->decodeHtmlJsonAttribute($raw);
    }

    private function extractConfigPayload(string $html): ?array
    {
        $patterns = [
            '/config\s*:\s*({.*?}),\s*childAttributes\s*:/s',
            '/this\.config\s*=\s*({.*?});/s',
            '/config:\s*({.*?})\s*,\s*childAttributes/s',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $html, $matches)) {
                continue;
            }

            $decoded = json_decode($matches[1], true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractSectionHtml(string $html, string $heading, array $nextHeadings): ?string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $headingPos = mb_stripos($decoded, $heading);

        if ($headingPos === false) {
            return null;
        }

        $start = $headingPos + mb_strlen($heading);

        while (isset($decoded[$start]) && in_array($decoded[$start], [':', ' ', "\n", "\r", "\t"], true)) {
            $start++;
        }

        $endCandidates = [];

        foreach ($nextHeadings as $nextHeading) {
            $pos = mb_stripos($decoded, $nextHeading, $start);

            if ($pos !== false) {
                $endCandidates[] = $pos;
            }
        }

        $hardStops = [
            "app.component('v-product'",
            'app.component("v-product"',
            'window.addEventListener("load"',
            "window.addEventListener('load'",
            'document.addEventListener(\'DOMContentLoaded\'',
            'document.addEventListener("DOMContentLoaded"',
            'window.checkAnalyticsConsent',
            '<script',
            '</script>',
        ];

        foreach ($hardStops as $marker) {
            $pos = mb_stripos($decoded, $marker, $start);

            if ($pos !== false) {
                $endCandidates[] = $pos;
            }
        }

        if ($endCandidates === []) {
            $chunk = mb_substr($decoded, $start, 2000);
        } else {
            $end = min($endCandidates);
            $chunk = mb_substr($decoded, $start, $end - $start);
        }

        $chunk = trim($chunk);

        return $chunk !== '' ? $chunk : null;
    }

    private function decodeHtmlJsonAttribute(string $value): ?array
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $json = json_decode($decoded, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $json;
    }
}
