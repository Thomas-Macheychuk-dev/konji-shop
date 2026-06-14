<?php

declare(strict_types=1);

namespace Tests\Feature\Peruka;

use App\Services\Peruka\PerukaProductScraper;
use Tests\TestCase;

final class PerukaProductScraperTest extends TestCase
{
    public function test_it_extracts_product_data_and_colour_product_links(): void
    {
        $scraper = app(PerukaProductScraper::class);

        $product = $scraper->extract($this->verbenaMe12Html(), 'https://www.peruka.pl/turban-verbena-me12-flower.html');

        $this->assertSame('peruka', $product['source']);
        $this->assertSame('38772', $product['external_product_id']);
        $this->assertSame('38772', $product['sku']);
        $this->assertSame('5900000106175', $product['ean']);
        $this->assertSame('https://www.peruka.pl/turban-verbena-me12-flower.html', $product['source_url']);
        $this->assertSame('Turban z oryginalnym węzłem różowy VERBENA ME12+B61', $product['name']);
        $this->assertSame('Rokoko Hair Company', $product['brand']);
        $this->assertSame(['Turbany', 'FLOWER'], $product['categories']);
        $this->assertSame('FLOWER', $product['category']);
        $this->assertSame(90.00, $product['price_gross_amount']);
        $this->assertSame('PLN', $product['currency']);
        $this->assertSame(33, $product['stock_quantity']);
        $this->assertSame('in_stock', $product['availability']);
        $this->assertFalse($product['is_medical_device']);
        $this->assertSame(
            '<h2>Turban z oryginalnym węzłem różowy VERBENA ME12+B61</h2><p>Elegancki <strong>turban</strong>.</p>',
            $product['description_html']
        );
        $this->assertSame([
            'https://www.peruka.pl/turban-verbena-dec63-flower.html',
        ], $product['variant_product_urls']);
        $this->assertSame([
            [
                'url' => 'https://www.peruka.pl/media/products/2c1da3f35b5376c52fa2d2ed2ebecbe3/images/flowers-verbena-me12-900x900.webp',
                'alt' => null,
            ],
            [
                'url' => 'https://www.peruka.pl/media/products/2c1/images/verbena-ME12-B61-side-900x900.webp',
                'alt' => 'Verbena',
            ],
            [
                'url' => 'https://www.peruka.pl/media/products/2c1/images/verbena-ME12-B61-back.webp',
                'alt' => 'Back',
            ],
        ], $product['images']);
    }

    public function test_it_detects_medical_device_products(): void
    {
        $scraper = app(PerukaProductScraper::class);

        $product = $scraper->extract($this->medicalProductHtml(), 'https://www.peruka.pl/fluff-6-8-4r.html');

        $this->assertSame('25228', $product['external_product_id']);
        $this->assertTrue($product['is_medical_device']);
        $this->assertSame(950.00, $product['price_gross_amount']);
        $this->assertSame(46, $product['stock_quantity']);
    }

    public function test_it_normalizes_only_peruka_product_urls(): void
    {
        $scraper = app(PerukaProductScraper::class);

        $this->assertSame(
            'https://www.peruka.pl/turban-verbena-dec63-flower.html',
            $scraper->normalizeProductUrl('/turban-verbena-dec63-flower.html?tracking=1', 'https://www.peruka.pl/turban-verbena-me12-flower.html')
        );

        $this->assertNull($scraper->normalizeProductUrl('/category/turbany-flower'));
        $this->assertNull($scraper->normalizeProductUrl('https://example.com/turban-verbena-dec63-flower.html'));
    }

    private function verbenaMe12Html(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta name="description" content="Turban z oryginalnym węzłem różowy VERBENA ME12+B61">
    <title>Turban z oryginalnym węzłem różowy VERBENA # ME12+B61 | Peruka.pl</title>
    <link rel="canonical" href="https://www.peruka.pl/turban-verbena-me12-flower.html" />
    <meta property="og:title" content="Turban z oryginalnym węzłem różowy VERBENA # ME12+B61" />
    <meta property="og:image" content="https://www.peruka.pl/media/products/2c1da3f35b5376c52fa2d2ed2ebecbe3/images/thumbnail/big_flowers-verbena-me12-900x900.webp?lm=1776977018" />
</head>
<body>
<ol class="breadcrumb hidden-xs">
    <li><a href="https://www.peruka.pl/"><span>Start</span></a></li>
    <li><a href="https://www.peruka.pl/category/turbany"><span>Turbany</span></a></li>
    <li><a href="https://www.peruka.pl/category/turbany-flower"><span>FLOWER</span></a></li>
    <li><a href="https://www.peruka.pl/turban-verbena-me12-flower.html"><span>Turban z oryginalnym węzłem różowy VERBENA # ME12+B61</span></a></li>
</ol>
<div itemscope itemtype="https://schema.org/Product">
    <ul id="product-gallery" class="gallery list-unstyled clearfix">
        <li id="product-photo" data-gallery="/media/products/2c1/images/thumbnail/gallery_verbena-ME12-B61-side-900x900.webp?lm=1">
            <img itemprop="image" src="/media/products/2c1/images/thumbnail/large_verbena-ME12-B61-side-900x900.webp?lm=1" alt="Verbena" />
        </li>
        <div class="gallery-item" data-src="/stThumbnailPlugin.php?i=media%2Fproducts%2F2c1%2Fimages%2Fverbena-ME12-B61-back.webp&t=&f=product&u=1">
            <img src="/media/products/2c1/images/thumbnail/gallery_verbena-ME12-B61-back.webp?lm=1" alt="Back" />
        </div>
    </ul>
    <h1 itemprop="name">Turban z oryginalnym węzłem różowy VERBENA  ME12+B61</h1>
    <span itemprop="brand"><a href="/manufacturer/rokoko-hair-company" class="producer_name">Rokoko Hair Company</a></span>
    <span id="st_availability_info-value">Produkt jest dostępny</span>
    <div id="product-colors">
        <div class="row zrList">
            <div class="selected"><div class="img"><img src="/selected.webp" /></div><br />ME12+B61</div>
            <div><div class="img"><a href="/turban-verbena-dec63-flower.html"><img src="/dec63.webp" /></div><br />DEC63 + B9</a></div>
        </div>
    </div>
    <meta itemprop="sku" content="38772">
    <meta itemprop="mpn" content="5900000106175" />
    <ul class="information prices" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
        <meta itemprop="availability" content="https://schema.org/InStock" />
        <meta itemprop="priceCurrency" content="PLN" />
        <meta itemprop="price" content="90.00" />
        <input type="text" class="basket_add_quantity form-control" data-max="33" data-min="1" />
    </ul>
    <div id="description-long" itemprop="description" class="description tinymce_html col-xs-12">
        <!--[mode:tiny]--><h2 data-start="1" data-end="2">Turban z oryginalnym węzłem różowy VERBENA ME12+B61</h2><p data-start="3" data-end="4">Elegancki <span data-start="5" data-end="6"><strong>turban</strong></span>.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function medicalProductHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="pl">
<head>
    <title>Półperuka syntetyczna termoodporna brąz z odrostem FLUFF # 6/8/4R | Peruka.pl</title>
    <link rel="canonical" href="https://www.peruka.pl/fluff-6-8-4r.html" />
</head>
<body>
<div itemscope itemtype="https://schema.org/Product">
    <h1 itemprop="name">Półperuka syntetyczna termoodporna brąz z odrostem FLUFF  6/8/4R</h1>
    <div class="product-observe observe-no" data-product-observe="25228"></div>
    <span itemprop="brand"><a href="/manufacturer/rokoko-hair-company" class="producer_name">Rokoko Hair Company</a></span>
    <li class="dsMedic">To jest wyrób medyczny. Używaj go zgodnie z instrukcją używania lub etykietą.</li>
    <span id="st_availability_info-value">Produkt jest dostępny</span>
    <meta itemprop="sku" content="25228">
    <meta itemprop="mpn" content="5900000077604" />
    <ul class="information prices" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
        <meta itemprop="availability" content="https://schema.org/InStock" />
        <meta itemprop="priceCurrency" content="PLN" />
        <meta itemprop="price" content="950.00" />
        <input type="text" class="basket_add_quantity form-control" data-max="46" data-min="1" />
    </ul>
    <div id="description-long" itemprop="description" class="description tinymce_html col-xs-12"><p>Opis produktu.</p></div>
</div>
</body>
</html>
HTML;
    }
}
