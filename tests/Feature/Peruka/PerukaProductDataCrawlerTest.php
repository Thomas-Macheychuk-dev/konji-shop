<?php

declare(strict_types=1);

namespace Tests\Feature\Peruka;

use App\Services\Peruka\PerukaProductDataCrawler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PerukaProductDataCrawlerTest extends TestCase
{
    public function test_it_scrapes_colour_links_as_independent_products_without_duplicate_loops(): void
    {
        Http::fake([
            'https://www.peruka.pl/turban-verbena-me12-flower.html' => Http::response($this->productHtml(
                id: '38772',
                name: 'Turban z oryginalnym węzłem różowy VERBENA ME12+B61',
                canonical: 'https://www.peruka.pl/turban-verbena-me12-flower.html',
                otherVariantHref: '/turban-verbena-dec63-flower.html',
                stock: 33,
            )),
            'https://www.peruka.pl/turban-verbena-dec63-flower.html' => Http::response($this->productHtml(
                id: '38773',
                name: 'Turban z oryginalnym węzłem beżowy VERBENA DEC63 + B9',
                canonical: 'https://www.peruka.pl/turban-verbena-dec63-flower.html',
                otherVariantHref: '/turban-verbena-me12-flower.html',
                stock: 56,
            )),
        ]);

        $result = app(PerukaProductDataCrawler::class)
            ->withRequestDelayMilliseconds(0)
            ->crawl(productUrls: ['https://www.peruka.pl/turban-verbena-me12-flower.html']);

        $this->assertSame(2, $result['product_count']);
        $this->assertSame([
            'https://www.peruka.pl/turban-verbena-dec63-flower.html',
            'https://www.peruka.pl/turban-verbena-me12-flower.html',
        ], $result['variant_product_urls']);
        $this->assertContains('https://www.peruka.pl/turban-verbena-me12-flower.html', $result['skipped_duplicate_urls']);
        $this->assertSame(['38772', '38773'], $result['scraped_external_ids']);
        $this->assertSame([], $result['failed_urls']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.peruka.pl/turban-verbena-me12-flower.html');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.peruka.pl/turban-verbena-dec63-flower.html');
    }

    public function test_it_skips_duplicate_external_ids(): void
    {
        Http::fake([
            'https://www.peruka.pl/product-a.html' => Http::response($this->productHtml(
                id: '111',
                name: 'Product A',
                canonical: 'https://www.peruka.pl/product-a.html',
                otherVariantHref: null,
                stock: 3,
            )),
            'https://www.peruka.pl/product-a-copy.html' => Http::response($this->productHtml(
                id: '111',
                name: 'Product A Copy',
                canonical: 'https://www.peruka.pl/product-a-copy.html',
                otherVariantHref: null,
                stock: 3,
            )),
        ]);

        $result = app(PerukaProductDataCrawler::class)
            ->withRequestDelayMilliseconds(0)
            ->crawl(productUrls: [
                'https://www.peruka.pl/product-a.html',
                'https://www.peruka.pl/product-a-copy.html',
            ]);

        $this->assertSame(1, $result['product_count']);
        $this->assertSame([
            'https://www.peruka.pl/product-a-copy.html' => '111',
        ], $result['skipped_duplicate_external_ids']);
    }

    private function productHtml(string $id, string $name, string $canonical, ?string $otherVariantHref, int $stock): string
    {
        $variantHtml = $otherVariantHref === null ? '' : <<<HTML
<div id="product-colors">
    <div class="row zrList">
        <div class="selected"><div class="img"><img src="/selected.webp" /></div><br />Current</div>
        <div><div class="img"><a href="{$otherVariantHref}"><img src="/other.webp" /></div><br />Other</a></div>
    </div>
</div>
HTML;

        return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
    <title>{$name} | Peruka.pl</title>
    <link rel="canonical" href="{$canonical}" />
</head>
<body>
<ol class="breadcrumb hidden-xs">
    <li><a href="https://www.peruka.pl/category/turbany"><span>Turbany</span></a></li>
    <li><a href="https://www.peruka.pl/category/turbany-flower"><span>FLOWER</span></a></li>
</ol>
<div itemscope itemtype="https://schema.org/Product">
    <h1 itemprop="name">{$name}</h1>
    <span itemprop="brand"><a href="/manufacturer/rokoko-hair-company" class="producer_name">Rokoko Hair Company</a></span>
    <span id="st_availability_info-value">Produkt jest dostępny</span>
    {$variantHtml}
    <meta itemprop="sku" content="{$id}">
    <meta itemprop="mpn" content="5900000000000" />
    <ul class="information prices" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
        <meta itemprop="availability" content="https://schema.org/InStock" />
        <meta itemprop="priceCurrency" content="PLN" />
        <meta itemprop="price" content="90.00" />
        <input type="text" class="basket_add_quantity form-control" data-max="{$stock}" data-min="1" />
    </ul>
    <div id="description-long" itemprop="description" class="description tinymce_html col-xs-12"><p>Opis produktu.</p></div>
</div>
</body>
</html>
HTML;
    }
}
