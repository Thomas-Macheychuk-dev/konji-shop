<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class WojdakProductPayloadExtractor
{
    private const WOJDAK_HOST = 'wojdak.pl';

    /**
     * @return array<string, mixed>
     */
    public function extract(string $html, ?string $sourceUrl = null): array
    {
        $crawler = new Crawler($html, $sourceUrl ?? 'https://wojdak.pl');
        $canonicalUrl = $this->extractCanonical($crawler) ?? $this->normalizeUrl((string) $sourceUrl);
        $name = $this->extractFirstText($crawler, '.single-product__about-title')
            ?? $this->cleanTitle($this->extractMetaProperty($crawler, 'og:title'))
            ?? $this->cleanTitle($this->extractFirstText($crawler, 'title'));

        $descriptionHtml = $this->extractHtml($crawler, '.single-product__about-text');
        $descriptionText = $this->normalizeWhitespace(strip_tags(html_entity_decode((string) $descriptionHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $categoryUrl = $this->extractCategoryUrl($crawler);
        $sizeTablePdfUrl = $this->extractSizeTablePdfUrl($crawler);

        return [
            'source_url' => $sourceUrl,
            'canonical_url' => $canonicalUrl,
            'external_id' => $this->externalIdFromUrl($canonicalUrl ?? $sourceUrl),
            'name' => $name,
            'title_tag' => $this->extractFirstText($crawler, 'title'),
            'meta_description' => $this->extractMetaByName($crawler, 'description'),
            'meta_og_description' => $this->extractMetaProperty($crawler, 'og:description'),
            'description_html' => $descriptionHtml,
            'description_text' => $descriptionText,
            'category_url' => $categoryUrl,
            'category_slug' => $this->slugFromCategoryUrl($categoryUrl),
            'size_table_pdf_url' => $sizeTablePdfUrl,
            'size_table_type' => $this->sizeTableType($sizeTablePdfUrl),
            'images' => $this->extractImages($crawler),
        ];
    }

    private function extractCanonical(Crawler $crawler): ?string
    {
        return $this->extractAttribute($crawler, 'link[rel="canonical"]', 'href');
    }

    private function extractMetaByName(Crawler $crawler, string $name): ?string
    {
        return $this->normalizeWhitespace($this->extractAttribute($crawler, sprintf('meta[name="%s"]', $name), 'content'));
    }

    private function extractMetaProperty(Crawler $crawler, string $property): ?string
    {
        return $this->normalizeWhitespace($this->extractAttribute($crawler, sprintf('meta[property="%s"]', $property), 'content'));
    }

    private function extractFirstText(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                return null;
            }

            return $this->normalizeWhitespace($node->first()->text());
        } catch (Throwable) {
            return null;
        }
    }

    private function extractHtml(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                return null;
            }

            $html = trim($node->first()->html());

            return $html === '' ? null : $html;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractAttribute(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                return null;
            }

            $value = $node->first()->attr($attribute);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractCategoryUrl(Crawler $crawler): ?string
    {
        $categoryUrl = null;

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$categoryUrl): void {
                if ($categoryUrl !== null) {
                    return;
                }

                $href = $node->attr('href');
                $text = $this->normalizeWhitespace($node->text());

                if (! is_string($href) || ! is_string($text)) {
                    return;
                }

                $normalized = $this->normalizeUrl($href);

                if ($normalized === null || ! str_contains((string) parse_url($normalized, PHP_URL_PATH), '/produkty/')) {
                    return;
                }

                if (str_contains(mb_strtolower($text), 'powrót') || str_contains(mb_strtolower($text), 'kategorii')) {
                    $categoryUrl = $this->normalizePathUrl($normalized);
                }
            });
        } catch (Throwable) {
            return null;
        }

        return $categoryUrl;
    }

    private function extractSizeTablePdfUrl(Crawler $crawler): ?string
    {
        $pdfUrl = null;

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$pdfUrl): void {
                if ($pdfUrl !== null) {
                    return;
                }

                $href = $node->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $normalized = $this->normalizeUrl($href);

                if ($normalized === null) {
                    return;
                }

                $lower = mb_strtolower($normalized);

                if (str_ends_with($lower, '.pdf') && str_contains($lower, 'tabela-rozmiar')) {
                    $pdfUrl = $normalized;
                }
            });
        } catch (Throwable) {
            return null;
        }

        return $pdfUrl;
    }

    /**
     * @return array<int, string>
     */
    private function extractImages(Crawler $crawler): array
    {
        $images = [];

        try {
            $crawler->filter('.single-product__gallery a.swiper-slide[href]')->each(function (Crawler $node) use (&$images): void {
                $href = $node->attr('href');

                if (! is_string($href) || trim($href) === '') {
                    return;
                }

                $url = $this->normalizeUrl($href);

                if ($url !== null && $this->isImageUrl($url)) {
                    $images[$url] = true;
                }
            });
        } catch (Throwable) {
            return [];
        }

        return array_keys($images);
    }

    private function cleanTitle(?string $title): ?string
    {
        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        $title = preg_replace('/\s+-\s+Wojdak\s*$/iu', '', $title) ?: $title;

        return $this->normalizeWhitespace($title);
    }

    private function externalIdFromUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/product/([^/]+)/?#i', $path, $matches) !== 1) {
            return null;
        }

        return mb_strtolower($matches[1]);
    }

    private function slugFromCategoryUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        return $segments === [] ? null : end($segments);
    }

    private function sizeTableType(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $lower = mb_strtolower($url);

        return match (true) {
            str_contains($lower, 'obuwie') => 'footwear',
            str_contains($lower, 'odziez') || str_contains($lower, 'odzież') => 'clothing',
            default => null,
        };
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = str_replace(['\\/', '\/'], '/', $url);

        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::WOJDAK_HOST.$url;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    private function normalizePathUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = '/'.trim($path, '/').'/';
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return 'https://'.self::WOJDAK_HOST.$path;
    }

    private function isImageUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(jpe?g|png|webp|gif)$/i', $path) === 1;
    }

    private function normalizeWhitespace(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $value === '' ? null : $value;
    }
}
