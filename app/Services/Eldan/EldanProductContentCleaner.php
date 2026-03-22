<?php

namespace App\Services\Eldan;

class EldanProductContentCleaner
{
    public function cleanHtml(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Hard stop before Vue/JS app code if it leaked into extracted content
        $cutMarkers = [
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

        foreach ($cutMarkers as $marker) {
            $pos = mb_stripos($html, $marker);

            if ($pos !== false) {
                $html = mb_substr($html, 0, $pos);
            }
        }

        // Remove script/style blocks if any still remain
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        // Remove Office/VML conditional blocks
        $html = preg_replace('/<!--\[if.*?<!\[endif\]-->/is', '', $html);

        // Remove VML/XML style tags
        $html = preg_replace('/<\/?(v:|o:)[^>]*>/i', '', $html);

        // Remove inline base64 images
        $html = preg_replace('/<img[^>]+src="data:image\/[^"]+"[^>]*>/i', '', $html);

        // Remove file:// image references
        $html = preg_replace('/<[^>]+src="file:\/\/\/[^"]+"[^>]*>/i', '', $html);

        // Remove empty spans
        $html = preg_replace('/<span[^>]*>\s*<\/span>/i', '', $html);

        // Remove Microsoft/editor attributes
        $html = preg_replace('/\s?(class|style|lang|xml:lang|data-start|data-end|width|height)="[^"]*"/i', '', $html);

        // Allow only safe tags
        $allowedTags = '<h2><h3><h4><p><br><strong><b><em><i><ul><ol><li><table><thead><tbody><tr><th><td>';
        $html = strip_tags($html, $allowedTags);

        // Remove empty paragraphs
        $html = preg_replace('/<p>\s*(?:&nbsp;|\x{00A0}|\s)*<\/p>/u', '', $html);

        // Fix broken sequences like </p><h3>...</h3></p>
        $html = str_replace('</h3></p>', '</h3>', $html);
        $html = str_replace('</h2></p>', '</h2>', $html);
        $html = str_replace('</h4></p>', '</h4>', $html);

        // Normalize whitespace between tags
        $html = preg_replace('/\s+/u', ' ', $html);
        $html = preg_replace('/>\s+</u', '><', $html);

        $html = trim($html);

        return $html !== '' ? $html : null;
    }

    public function cleanShortHtml(?string $html): ?string
    {
        return $this->cleanHtml($html);
    }
}
