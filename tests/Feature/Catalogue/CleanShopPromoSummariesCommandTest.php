<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('replaces supplier sales lines in short descriptions with the first description paragraph', function (): void {
    $promo = '<p>Kup Pieluchy anatomiczne Seni San Maxi za 21,69 zł | Darmowa dostawa od 119 zł - już w 24h | Zobacz inne z kategorii Pieluchy anatomiczne Seni San</p>';

    $seni = promoProduct('seni-san-maxi', $promo, '<p>Seni San Maxi to oddychające pieluchy anatomiczne.</p><p>Druga część opisu.</p>');
    $noDescription = promoProduct('bez-opisu', $promo, null);
    $clean = promoProduct('czysty', '<p>Miękki kołnierz ortopedyczny.</p>', '<p>Opis.</p>');

    $this->artisan('catalogue:clean-shop-promo-summaries')
        ->expectsOutputToContain('PROMO_SUMMARIES=2 REPLACED_WITH_DESCRIPTION=1 CLEARED=1')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($seni->fresh()->short_description)->toBe($promo);

    $this->artisan('catalogue:clean-shop-promo-summaries', ['--write' => true])->assertSuccessful();

    expect($seni->fresh()->short_description)->toBe('<p>Seni San Maxi to oddychające pieluchy anatomiczne.</p>')
        ->and($noDescription->fresh()->short_description)->toBeNull()
        ->and($clean->fresh()->short_description)->toBe('<p>Miękki kołnierz ortopedyczny.</p>');

    $backup = collect(Storage::disk('local')->files('seo-repairs'))->sole();
    $this->artisan('catalogue:clean-shop-promo-summaries', ['--rollback' => $backup])->assertSuccessful();

    expect($seni->fresh()->short_description)->toBe($promo);
});

it('rebuilds supplier sales-line meta descriptions from the product description', function (): void {
    $product = promoProduct('seni-super', null, '<p>Seni Super to pieluchomajtki zapinane na rzepy dla osób z ciężkim nietrzymaniem moczu. Mają elastyczne przylepce wielokrotnego zapinania.</p>');
    $product->forceFill(['seo_description' => 'Kup Seni Super pieluchomajtki za 19,25 zł | Darmowa dostawa od 119 zł - już w 24h'])->save();

    $this->artisan('seo:product-meta-descriptions', ['--write' => true])->assertSuccessful();

    expect($product->fresh()->getRawOriginal('seo_description'))->toStartWith('Seni Super to pieluchomajtki zapinane na rzepy')
        ->and($product->fresh()->getRawOriginal('seo_description'))->not->toContain('19,25');
});

function promoProduct(string $slug, ?string $short, ?string $description): Product
{
    return Product::query()->create([
        'external_source' => 'seni24',
        'external_id' => $slug,
        'name' => 'Produkt '.$slug,
        'slug' => $slug,
        'status' => ProductStatus::ACTIVE,
        'short_description' => $short,
        'description' => $description,
    ]);
}
