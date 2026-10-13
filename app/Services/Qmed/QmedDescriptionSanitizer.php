<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class QmedDescriptionSanitizer
{
    private const ALLOWED_TAGS = [
        'article', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'blockquote', 'a',
    ];

    private const DISCARD_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'form', 'input', 'button', 'textarea', 'select', 'option',
        'template', 'link', 'meta', 'base', 'frame', 'frameset',
        'video', 'audio', 'source', 'canvas', 'noscript',
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $fragment = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
                .'<body><div id="qmed-sanitizer-root">'.$html.'</div></body></html>';

            if (! $document->loadHTML($fragment, LIBXML_NONET)) {
                throw new RuntimeException('Cannot parse Qmed product description.');
            }

            $root = (new DOMXPath($document))->query('//div[@id="qmed-sanitizer-root"]')->item(0);
            if (! $root instanceof DOMElement) {
                throw new RuntimeException('Qmed description wrapper is missing.');
            }

            return $this->renderChildren($root);
        } catch (\Throwable) {
            // Fail closed: do not expose untrusted HTML after a parsing error.
            return '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function renderChildren(DOMNode $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DISCARD_TAGS, true)) {
            return '';
        }

        $children = $this->renderChildren($node);
        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // Keep text from unknown presentational wrappers, but never their attributes.
            return $children;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        $attributes = '';
        if ($tag === 'a') {
            $url = trim($node->getAttribute('href'));
            if ($this->isSafeHref($url)) {
                $attributes = ' href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'"';
            }
        }

        return '<'.$tag.$attributes.'>'.$children.'</'.$tag.'>';
    }

    private function isSafeHref(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        return preg_match('~^https?://[^/?#]+(?:[/?#]|$)~i', $url) === 1;
    }
}
