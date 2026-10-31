<?php

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';

function seoMetadataFixture(array $overrides = []): array
{
    return $overrides + ['id' => 1, 'name' => 'Poduszka model ABC', 'slug' => 'poduszka-abc', 'external_source' => 'supplier', 'external_id' => 'ABC', 'seo_title' => null, 'seo_description' => null];
}

it('uses clean factual attributes and rejects dirty values and variant guesses', function (): void {
    $result = ProductSeoMetadata::propose(seoMetadataFixture(), [
        'Kolor' => ['biały', 'czarny'],
        'Wymiary' => ['0 cm'],
        'Symbol' => ['ABC-15'],
        'Materiał' => ['bawełna'],
        'Producent' => ['Sklep ✔️ Certyfikat CE ✅'],
    ]);
    expect($result['after']['seo_description'])->toContain('ABC-15', 'bawełna');
    expect($result['after']['seo_description'])->not->toContain('biały', 'czarny', '0 cm', '✔', 'CE');
    expect(mb_strlen($result['after']['seo_description']))->toBeLessThanOrEqual(160);
});

it('normalises markup and unicode spaces without storing script or style contents', function (): void {
    $plain = ProductSeoMetadata::plain('&lt;p&gt;Model&nbsp;ABC&lt;/p&gt;<script>unwanted()</script><style>.hidden{}</style>');
    expect($plain)->toBe('Model ABC');
    $result = ProductSeoMetadata::propose(seoMetadataFixture(['seo_title' => '<b>Model ABC</b>', 'seo_description' => '<p>Opis&nbsp;modelu ABC.</p>']));
    expect($result['after'])->toBe(['seo_title' => 'Model ABC', 'seo_description' => 'Opis modelu ABC.']);
});

it('rebuilds foreign shop price and shipping promises without inventing stock or benefits', function (): void {
    $result = ProductSeoMetadata::propose(seoMetadataFixture([
        'seo_title' => 'Model ABC | medi sklep internetowy',
        'seo_description' => 'Kup produkt za 12,99 zł w Seni24.pl. Darmowa dostawa już w 24h.',
    ]));
    expect($result['after']['seo_title'])->toBe('Model ABC');
    expect($result['after']['seo_description'])->toContain('Poduszka model ABC');
    expect($result['after']['seo_description'])->not->toContain('12,99', 'Seni24', '24h', 'Darmowa', 'InStock');
    expect(ProductSeoMetadata::cleanTitle('Model ABC - GaleriaZdrowia.com'))->toBe('Model ABC');
    expect(ProductSeoMetadata::foreignReference('Sprawdź produkt w Ortezka.pl.'))->toBeFalse();
    expect(ProductSeoMetadata::foreignReference('Sprawdź produkt w GaleriaZdrowia.pl'))->toBeTrue();
});

it('retains distinguishing suffixes within the database title limit', function (): void {
    $name = str_repeat('Długi opis modelu ', 20).' – MODEL-Z 200×45 mm';
    $result = ProductSeoMetadata::propose(seoMetadataFixture(['name' => $name]));
    expect(mb_strlen($result['after']['seo_title']))->toBeLessThanOrEqual(255);
    expect($result['after']['seo_title'])->toEndWith('MODEL-Z 200×45 mm');
    expect($result['after']['seo_description'])->not->toBeEmpty();
});

it('replaces zero and placeholder metadata instead of treating it as useful content', function (): void {
    $result = ProductSeoMetadata::propose(seoMetadataFixture(['seo_title' => '0', 'seo_description' => 'brak opisu']));
    expect($result['after']['seo_title'])->toBe('Poduszka model ABC');
    expect($result['after']['seo_description'])->toContain('Poduszka model ABC');
    expect(fn () => ProductSeoMetadata::propose(seoMetadataFixture(['name' => '0', 'seo_title' => '0'])))
        ->toThrow(RuntimeException::class, 'No safe title');
});
