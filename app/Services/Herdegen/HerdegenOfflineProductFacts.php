<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use DOMDocument;
use DOMXPath;

final class HerdegenOfflineProductFacts
{
    public function extract(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $facts = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $value = json_decode($node->textContent, true);
            if (!is_array($value)) {
                continue;
            }
            foreach ($this->products($value) as $product) {
                $properties = $product['additionalProperty'] ?? [];
                if (!is_array($properties)) {
                    continue;
                }
                foreach ($properties as $property) {
                    if (!is_array($property) || !is_string($property['name'] ?? null)) {
                        continue;
                    }
                    $name = trim(strip_tags($property['name']));
                    $rawValue = $property['value'] ?? null;
                    if (!is_string($rawValue) && !is_numeric($rawValue)) {
                        continue;
                    }
                    $valueText = trim(strip_tags((string) $rawValue));
                    if ($name === '' || $valueText === '' || mb_strlen($name) > 70 || mb_strlen($valueText) > 120) {
                        continue;
                    }
                    // Exclude claims that require manufacturer verification or commercial permission.
                    if (preg_match('/cena|price|stock|dostępno|availability|refund|nfz|certyf|medical|medyczn|lecz|terap|ean|sku|gtin|mpn/ui', $name)) {
                        continue;
                    }
                    $facts[$name] = $valueText;
                    if (count($facts) >= 15) {
                        break 2;
                    }
                }
            }
        }
        return $facts;
    }

    /** Extract authorized product copy without scripts, event attributes, or retailer widgets. */
    public function extractDescription(string $html): ?string
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $container = $xpath->query('//*[@itemprop="description" and contains(concat(" ", normalize-space(@class), " "), " product-description ")]')->item(0)
            ?? $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-description ")]')->item(0);
        if (!$container instanceof \DOMElement) {
            return null;
        }
        $parts = [];
        foreach ($container->childNodes as $child) {
            $parts[] = $this->safeMarkup($child);
        }
        $result = trim(implode('', $parts));
        return $result !== '' && trim(strip_tags($result)) !== '' ? $result : null;
    }

    private function safeMarkup(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'img', 'picture', 'video', 'audio', 'noscript'], true)) {
            return '';
        }
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        if ($tag === 'p' && preg_match('/(?:depositphotos|drukarnia chroma|obraz licencjonowany)/ui', $text)) {
            return '';
        }
        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= $this->safeMarkup($child);
        }
        $allowed = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'blockquote', 'br'];
        if (!in_array($tag, $allowed, true)) {
            return $children;
        }
        if ($tag === 'br') {
            return '<br>';
        }
        if (trim(strip_tags($children)) === '' && !in_array($tag, ['table', 'tr', 'tbody', 'thead'], true)) {
            return '';
        }
        return '<'.$tag.'>'.$children.'</'.$tag.'>';
    }

    private function products(array $node): array
    {
        if (array_is_list($node)) {
            return array_merge([], ...array_map(fn ($child) => is_array($child) ? $this->products($child) : [], $node));
        }
        $type = $node['@type'] ?? null;
        if ($type === 'Product' || (is_array($type) && in_array('Product', $type, true))) {
            return [$node];
        }
        $products = [];
        foreach (['@graph', 'itemListElement'] as $key) {
            if (is_array($node[$key] ?? null)) {
                $products = array_merge($products, $this->products($node[$key]));
            }
        }
        if (is_array($node['item'] ?? null)) {
            $products = array_merge($products, $this->products($node['item']));
        }
        return $products;
    }
}
