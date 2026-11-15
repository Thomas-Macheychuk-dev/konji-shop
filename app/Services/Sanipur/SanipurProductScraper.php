<?php

declare(strict_types=1);

namespace App\Services\Sanipur;

use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Parses one sanipur.eu (nopCommerce) product page. Returns null for pages that are not product pages
 * (categories, topics, blog), which the sitemap lists alongside products. Product pages without a price
 * ("0,00 zł", not sold on sanipur.eu) come back with price_gross_amount null.
 */
final class SanipurProductScraper
{
    public const HOST = 'sanipur.eu';

    /**
     * @return array<string, mixed>|null
     */
    public function parse(string $html, string $sourceUrl): ?array
    {
        try {
            $crawler = new Crawler($html, $sourceUrl);
            $form = $crawler->filter('#product-details-form');
        } catch (Throwable) {
            return null;
        }

        if ($form->count() === 0) {
            return null;
        }

        $productId = $this->productId($form);
        $priceNode = $productId !== null ? $form->filter('#price-value-'.$productId) : $form->filter('.product-price span');
        $price = $priceNode->count() > 0 ? $this->money($priceNode->first()->text('')) : null;

        if ($productId === null) {
            return null;
        }

        $name = $this->firstText($form, '.product-name h1');
        $slug = $this->slugFromUrl($sourceUrl);
        $descriptionHtml = $this->firstHtml($crawler, '#quickTab-description .full-description')
            ?? $this->firstHtml($form, '.full-description');
        $bodyText = $this->text($form->text(''));

        return [
            'source' => 'sanipur',
            'source_url' => $sourceUrl,
            'canonical_url' => $this->canonicalUrl($sourceUrl),
            'external_product_id' => $productId,
            'slug' => $slug,
            'name' => $name,
            'sku' => $this->firstText($form, '#sku-'.$productId),
            'gtin' => $this->firstText($form, '#gtin-'.$productId),
            'brand' => $this->firstText($form, '.manufacturers .value a') ?? $this->firstText($form, '.manufacturers .value'),
            'breadcrumbs' => $this->breadcrumbs($crawler),
            'short_description' => $this->withoutPlaceholder($this->firstText($form, '.short-description')),
            'description_html' => $descriptionHtml,
            'price_gross_amount' => $price,
            'old_price_gross_amount' => $this->oldPrice($form),
            'currency' => 'PLN',
            'availability_label' => $this->firstText($form, '.stock .value'),
            'is_medical_device' => preg_match('/wyr[oó]b(?:em|u)?\s+medyczn/iu', $bodyText) === 1,
            'has_attribute_options' => $form->filter('[name^="product_attribute_"]')->count() > 0,
            'attributes' => $this->specifications($crawler),
            'images' => $this->images($form, $name),
        ];
    }

    private function productId(Crawler $form): ?string
    {
        $node = $form->filter('[id^="price-value-"]');

        if ($node->count() > 0 && preg_match('/^price-value-(\d+)$/', (string) $node->first()->attr('id'), $m) === 1) {
            return $m[1];
        }

        $button = $form->filter('.add-to-cart-button[data-productid]');

        if ($button->count() > 0 && ctype_digit((string) $button->first()->attr('data-productid'))) {
            return (string) $button->first()->attr('data-productid');
        }

        return null;
    }

    private function oldPrice(Crawler $form): ?float
    {
        $node = $form->filter('.old-product-price');

        return $node->count() > 0 ? $this->money($node->first()->text('')) : null;
    }

    /**
     * @return list<string>
     */
    private function breadcrumbs(Crawler $crawler): array
    {
        $names = [];

        $crawler->filter('.breadcrumb li[itemprop="itemListElement"] a span[itemprop="name"]')
            ->each(function (Crawler $node) use (&$names): void {
                $name = $this->text($node->text(''));

                if ($name !== '') {
                    $names[] = $name;
                }
            });

        return $names;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function specifications(Crawler $crawler): array
    {
        $rows = [];

        $crawler->filter('.product-specs-box tr, #quickTab-specifications tr')->each(function (Crawler $row) use (&$rows): void {
            $label = $row->filter('.spec-name');
            $value = $row->filter('.spec-value');

            if ($label->count() === 0 || $value->count() === 0) {
                return;
            }

            $label = trim($this->text($label->text('')), ' :');
            $value = $this->text($value->text(''));

            if ($label !== '' && $value !== '') {
                $rows[$label.'|'.$value] = ['label' => $label, 'value' => $value];
            }
        });

        return array_values($rows);
    }

    /**
     * @return list<array{url: string, alt: string}>
     */
    private function images(Crawler $form, ?string $name): array
    {
        $images = [];

        $form->filter('.picture-gallery [data-full-image-url]')->each(function (Crawler $node) use (&$images, $name): void {
            $url = $this->imageUrl((string) $node->attr('data-full-image-url'));

            if ($url !== null && ! isset($images[$url])) {
                $images[$url] = ['url' => $url, 'alt' => $name ?? ''];
            }
        });

        if ($images === []) {
            $form->filter('.picture-gallery img')->each(function (Crawler $node) use (&$images, $name): void {
                $url = $this->imageUrl((string) ($node->attr('data-lazyloadsrc') ?: $node->attr('src')));

                if ($url !== null && ! isset($images[$url])) {
                    $images[$url] = ['url' => $url, 'alt' => $name ?? ''];
                }
            });
        }

        return array_values($images);
    }

    private function imageUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            $url = 'https://'.self::HOST.$url;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) === self::HOST ? $url : null;
    }

    private function canonicalUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return 'https://'.self::HOST.'/'.ltrim($path, '/');
    }

    private function slugFromUrl(string $url): string
    {
        $path = rawurldecode(trim((string) parse_url($url, PHP_URL_PATH), '/'));
        $last = basename($path);

        return Str::slug($last) ?: substr(sha1($url), 0, 16);
    }

    private function money(string $value): ?float
    {
        $value = str_replace(["\xc2\xa0", ' '], '', $this->text($value));

        if (preg_match('/(\d+(?:[.,]\d{1,2})?)(?:zł|pln)/iu', $value, $m) !== 1) {
            return null;
        }

        $amount = (float) str_replace(',', '.', $m[1]);

        return $amount > 0 ? $amount : null;
    }

    private function firstText(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $text = $this->text($node->first()->text(''));

        return $text === '' ? null : $text;
    }

    private function firstHtml(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $html = trim($node->first()->html(''));

        return $html === '' ? null : $html;
    }

    /**
     * sanipur.eu shows a template placeholder ("CustomValue") on a few pages instead of a summary.
     */
    private function withoutPlaceholder(?string $value): ?string
    {
        return $value === null || preg_match('/^custom\s*value$/iu', $value) === 1 ? null : $value;
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
