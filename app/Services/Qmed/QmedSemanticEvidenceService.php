<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Manufacturer description evidence only; never authorises sale. */
final class QmedSemanticEvidenceService
{
    private function plain(string $html): string {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~isu', ' ', $html) ?? $html;
        // Preserve word boundaries across inline and block HTML tags; strip_tags()
        // alone joins e.g. "ROZMIAR</strong><span>Uniwersalny".
        $html = preg_replace('~<(?:br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>~iu', ' ', $html) ?? $html;
        $html = preg_replace('~<[^>]+>~u', ' ', $html) ?? $html;
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{00AD}"], [' ', '', ''], $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    public function classify(string $html): array {
        $text = $this->plain($html);
        $rules = [
            'EXPLICIT_UNIVERSAL_SIZE' => '~\b(?:rozmiar\s*:?\s*(?:jest\s*)?uniwersaln\p{L}*|uniwersaln\p{L}*\s+rozmiar\p{L}*|jeden\s+rozmiar|one\s+size)(?!\p{L})~iu',
            'EXPLICIT_SIDE_VERSIONS' => '~\b(?:strona\s+prawa\s+i\s+lewa|wersj\w*\s+(?:na\s+)?praw\w*\s+i\s+lew\w*|wersj\w*\s+lew\w*\s+i\s+praw\w*|wersj\w*\s+praw\w*\s+(?:lub|/)\s+lew\w*|wersj\w*\s+lew\w*\s+(?:lub|/)\s+praw\w*|dost[eę]pn\w*\s+wersj\w*\s+praw\w*\s+i\s+lew\w*)\b~iu',
            'BILATERAL_COMPATIBILITY' => '~\b(?:uniwersaln\p{L}*\s+zaopatrzen\p{L}*\s+dla\s+praw\p{L}*\s+i\s+lew\p{L}*|uniwersaln\w*\s+zaopatrzen\w*\s+(?:dla\s+)?praw\w*\s+i\s+lew\w*|zaopatrzen\w*\s+uniwersaln\w*\s+na\s+praw\w*\s+i\s+lew\w*|(?:rozmiar\s+uniwersaln\w*|uniwersaln\w*)\s+na\s+lew\w*\s+i\s+praw\w*|uniwersaln\w*\s+zaopatrzen\w*\s+(?:na\s+)?praw\w*\s+i\s+lew\w*|uniwersaln\w*\s+na\s+praw\w*\s+i\s+lew\w*)\b~iu',
            'ADJUSTABLE_FIT' => '~\b(?:regulacj\w*\s+(?:długości|obwod\w*|szerokości|wysokości)|regulowan\w*\s+(?:taśm\w*|pas\w*|długości)|dopasowan\w*\s+(?:długości|obwod\w*|ramy|ortezy)|indywidualn\w*\s+dopasowan\w*)\b~iu',
            'EXPLICIT_CUSTOM_ORDER' => '~\b(?:wyr[oó]b\s+wykonan\w*\s+na\s+zam[oó]wienie|wykonywan\w*\s+na\s+zam[oó]wienie|produk\w*\s+na\s+indywidualn\w*\s+zam[oó]wienie|wykonan\w*\s+na\s+miar[eę])\b~iu',
            'SET_CONTAINS_MULTIPLE_SIZES' => '~\b(?:w\s+zestawie\s+(?:jest|znajduj\w*|zawier\w*)?\s*(?:pi[eę][cć]|[2-9])\s+r[oó]żn\w*\s+rozmiar\w*|w\s+zestawie\s+(?:pi[eę][cć]|[2-9])\s+r[oó]żn\w*\s+rozmiar\w*|zestaw\s+zawiera\s+(?:pi[eę][cć]|[2-9])\s+r[oó]żn\w*\s+rozmiar\w*)\b~iu',
        ];
        $evidence = [];
        foreach ($rules as $category => $regex) {
            $m = [];
            $n = preg_match_all($regex, $text, $m, PREG_OFFSET_CAPTURE);
            if ($n === false) throw new RuntimeException('Invalid rule: ' . $category);
            if (!$n) continue;
            $items = [];
            foreach ($m[0] as [$match, $byteOffset]) {
                $snippet = trim(mb_strcut($text, max(0, $byteOffset - 70), strlen($match) + 140, 'UTF-8'));
                $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $match));
                $items[$key] = ['matched_text' => $match, 'excerpt' => $snippet];
            }
            $evidence[$category] = array_values($items);
        }
        $flags = [];
        $separate = isset($evidence['EXPLICIT_SIDE_VERSIONS']);
        $bilateral = isset($evidence['BILATERAL_COMPATIBILITY']);
        // Detect standalone anatomical side words, not prefixes such as "prawidłowe".
        $sideTerms = '~\b(?:praw(?:a|y|e|ej|ą|ego|ym|ych|o)|lew(?:a|y|e|ej|ą|ego|ym|ych|o))\b~iu';
        $sideMention = preg_match($sideTerms, $text) === 1;
        if ($separate && $bilateral) {
            $sideStatus = 'CONFLICTING_SIDE_EVIDENCE';
            $flags[] = 'CONFLICTING_SIDE_EVIDENCE';
        } elseif ($separate) {
            $sideStatus = 'EXPLICIT_SIDE_VERSIONS';
        } elseif ($bilateral) {
            $sideStatus = 'BILATERAL_COMPATIBILITY';
        } elseif ($sideMention) {
            $sideStatus = 'SIDE_CONFIGURATION_UNRESOLVED';
            $flags[] = 'SIDE_CONFIGURATION_UNRESOLVED';
        } else {
            $sideStatus = 'NO_SIDE_CONFIGURATION_EVIDENCE';
        }
        if (!isset($evidence['EXPLICIT_UNIVERSAL_SIZE'])) $flags[] = 'SIZE_UNRESOLVED';
        return ['evidence_categories' => array_keys($evidence), 'evidence' => $evidence,
            'side_evidence_status' => $sideStatus,
            'side_evidence_present' => $sideMention || $separate || $bilateral,
            'review_flags' => $flags, 'commercially_verified' => false,
            'proposed_skus' => [], 'proposed_variants' => []];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $review @return array<string,mixed> */
    public function analyse(array $source, array $review): array
    {
        if (!is_array($source['products'] ?? null) || !is_array($review['products'] ?? null)) {
            throw new RuntimeException('Missing product inventory.');
        }
        $byUrl = [];
        foreach ($source['products'] as $p) {
            $url = $p['source_url'] ?? null;
            if (!is_string($url) || $url === '' || isset($byUrl[$url])) {
                throw new RuntimeException('Invalid or duplicate source URL.');
            }
            $byUrl[$url] = $p;
        }
        if (count($byUrl) !== 156 || count($review['products']) !== 156) {
            throw new RuntimeException('Unexpected Qmed baseline.');
        }
        $rows = []; $seen = []; $freq = [];
        foreach ($review['products'] as $item) {
            $url = $item['source_url'] ?? null;
            if (!is_string($url) || isset($seen[$url]) || !isset($byUrl[$url])) {
                throw new RuntimeException('Invalid or duplicate review entry.');
            }
            $seen[$url] = true;
            if (count($item['size_evidence'] ?? []) !== 0) continue;
            $product = $byUrl[$url];
            $sections = array_filter($product['description_sections_html'] ?? [], 'is_string');
            $result = $this->classify((string) ($product['description_html'] ?? '') . ' ' . implode(' ', $sections));
            foreach ($result['evidence_categories'] as $cat) {
                $freq[$cat] = ($freq[$cat] ?? 0) + 1;
            }
            $rows[] = ['name' => $product['name'], 'source_url' => $url, 'external_id' => $product['external_id'] ?? null] + $result;
        }
        if (count($seen) !== 156 || count($rows) !== 39) {
            throw new RuntimeException('Expected 39 no-candidate products.');
        }
        ksort($freq);
        return [
            'source' => 'qmed',
            'analysis_type' => 'semantic_description_evidence_unverified_v4',
            'summary' => [
                'products' => 156, 'products_without_candidates' => count($rows),
                'category_product_counts' => $freq,
                'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0,
            ],
            'products' => $rows,
        ];
    }
}
