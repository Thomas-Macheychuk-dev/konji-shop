<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenOfflineProductFacts;
use PHPUnit\Framework\TestCase;

final class HerdegenOfflineProductFactsTest extends TestCase
{
    public function test_it_extracts_structured_facts_but_not_prices_or_description(): void
    {
        $html = '<script type="application/ld+json">'.json_encode([
            '@type' => 'Product', 'description' => 'Do not copy this description',
            'additionalProperty' => [
                ['name' => 'Długość', 'value' => '82 cm'],
                ['name' => 'Cena', 'value' => '99 PLN'],
            ],
        ], JSON_THROW_ON_ERROR).'</script>';
        self::assertSame(['Długość' => '82 cm'], (new HerdegenOfflineProductFacts())->extract($html));
    }

    public function test_it_preserves_tables_and_sanitizes_authorized_description(): void
    {
        $html = '<div class="product-description cms-content" itemprop="description">'
            .'<h2>Opis</h2><p onclick="evil()">Opis <strong>produktu</strong>.</p>'
            .'<p>Obraz licencjonowany przez Depositphotos.com/Drukarnia Chroma</p>'
            .'<table><tr><td>Materiał</td><td>Bawełna</td></tr></table>'
            .'<script>alert(1)</script><img src="https://evil.test/spy.png">'
            .'<a href="javascript:alert(1)">Czytaj więcej</a></div>';
        $description = (new HerdegenOfflineProductFacts())->extractDescription($html);
        self::assertNotNull($description);
        self::assertStringContainsString('<strong>produktu</strong>', $description);
        self::assertStringContainsString('<table>', $description);
        self::assertStringContainsString('Czytaj więcej', $description);
        foreach (['onclick', 'javascript:', '<script', '<img', 'Depositphotos', 'alert(1)', '<a '] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $description);
        }
    }

    public function test_it_requires_product_description_container(): void
    {
        self::assertNull((new HerdegenOfflineProductFacts())->extractDescription('<div><p>Other text</p></div>'));
    }

    public function test_it_returns_no_facts_if_page_contains_only_product_description(): void
    {
        $html = '<html><script type="application/ld+json">'.json_encode([
            '@type' => 'Product', 'description' => 'Retailer advertising content',
        ], JSON_THROW_ON_ERROR).'</script></html>';
        self::assertSame([], (new HerdegenOfflineProductFacts())->extract($html));
    }
}
