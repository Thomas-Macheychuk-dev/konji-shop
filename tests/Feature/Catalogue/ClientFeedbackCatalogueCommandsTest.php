<?php

use App\Console\Commands\PutSeniModelFirstCommand;
use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('raises nitrile gloves to 16 zł for blue and black and 18 zł for other colours', function (): void {
    $blue = feedbackVariant('Rękawice nitrylowe niebieskie Nitrylex Classic Mercator', 1398);
    $black = feedbackVariant('Rękawice nitrylowe czarne Nitrile Black easyCARE 100 szt.', 1229);
    $pink = feedbackVariant('Rękawice nitrylowe różowe Nitrylex Pink Mercator', 1659);
    $dearer = feedbackVariant('Rękawiczki jednorazowe nitrylowe Ambulex Nitryl niebieskie 100 szt.', 2129);
    $sterile = feedbackVariant('Rękawiczki jednorazowe nitrylowe jałowe Nitrylex Sterile Mercator 50 par', 1000);
    $noColour = feedbackVariant('Rękawiczki nitrylowe Gripzzly 50 szt.', 1000);
    $berry = feedbackVariant('Rękawice nitrylowe malinowe Nitrile Berry easyCARE', 1599);

    $this->artisan('catalogue:price-nitrile-gloves')
        ->expectsOutputToContain('PRICE_CHANGES=4 ALREADY_AT_OR_ABOVE_MINIMUM=1 SKIPPED=2')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    $this->artisan('catalogue:price-nitrile-gloves', ['--write' => true])->assertSuccessful();

    expect($blue->fresh()->price_gross_amount)->toBe(1600)
        ->and($blue->fresh()->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(1600))
        ->and($black->fresh()->price_gross_amount)->toBe(1600)
        ->and($pink->fresh()->price_gross_amount)->toBe(1800)
        ->and($berry->fresh()->price_gross_amount)->toBe(1800)
        ->and($dearer->fresh()->price_gross_amount)->toBe(2129)
        ->and($sterile->fresh()->price_gross_amount)->toBe(1000)
        ->and($noColour->fresh()->price_gross_amount)->toBe(1000);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();
    $this->artisan('catalogue:price-nitrile-gloves', ['--rollback' => $backup])->assertSuccessful();

    expect($pink->fresh()->price_gross_amount)->toBe(1659);
});

it('rounds prices to whole złoty in the equipment categories and their subcategories only', function (): void {
    $root = feedbackCategory('Mobilność i transport');
    $wheelchairs = feedbackCategory('Wózki inwalidzkie', $root);
    $hygiene = feedbackCategory('Higiena i nietrzymanie moczu');

    $wheelchair = feedbackVariant('Wózek inwalidzki s-ergo 305', 152280, $wheelchairs);
    $cheap = feedbackVariant('Laska', 12503, $root);
    $whole = feedbackVariant('Kula', 9000, $root);
    $diapers = feedbackVariant('Seni Super pieluchomajtki', 6973, $hygiene);
    $catheter = feedbackVariant('Cewnik urologiczny Nelaton 1 szt.', 65, $root);
    $syringe = feedbackVariant('Strzykawka', 999, $root);
    $needles = feedbackVariant('Igły', 1029, $root);

    $this->artisan('catalogue:round-prices', ['category' => ['Mobilność i transport'], '--write' => true])
        ->expectsOutputToContain('MIN_PRICE=10.00')
        ->expectsOutputToContain('PRICE_CHANGES=3 SKIPPED=0')
        ->assertSuccessful();

    expect($wheelchair->fresh()->price_gross_amount)->toBe(152300)
        ->and($cheap->fresh()->price_gross_amount)->toBe(12500)
        ->and($cheap->fresh()->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(12500))
        ->and($whole->fresh()->price_gross_amount)->toBe(9000)
        ->and($diapers->fresh()->price_gross_amount)->toBe(6973)
        ->and($catheter->fresh()->price_gross_amount)->toBe(65)
        ->and($syringe->fresh()->price_gross_amount)->toBe(999)
        ->and($needles->fresh()->price_gross_amount)->toBe(1000);
});

it('refuses category names it does not know', function (): void {
    $this->artisan('catalogue:round-prices', ['category' => ['Nie ma takiej']])
        ->expectsOutputToContain('UNKNOWN_CATEGORIES=Nie ma takiej')
        ->assertFailed();
});

it('archives the Timago dental models and restores them on rollback', function (): void {
    $model = feedbackVariant('Model A20A-200 (zęby 2-warstwowe)', null, null, 'timago', '528')->product;
    $dressing = feedbackVariant('Opatrunki żelowe Cutanplast', null, null, 'timago', '741')->product;

    $this->artisan('timago:archive-dental', ['--write' => true])
        ->expectsOutputToContain('FOUND=1 TO_ARCHIVE=1')
        ->expectsOutputToContain('ARCHIVED=1')
        ->assertSuccessful();

    expect($model->fresh()->status)->toBe(ProductStatus::ARCHIVED)
        ->and($dressing->fresh()->status)->toBe(ProductStatus::ACTIVE);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();
    $this->artisan('timago:archive-dental', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED=1 OF 1')
        ->assertSuccessful();

    expect($model->fresh()->status)->toBe(ProductStatus::ACTIVE);
});

it('puts the Seni product line first in Seni names', function (string $name, ?string $expected): void {
    expect(PutSeniModelFirstCommand::modelFirst($name))->toBe($expected);
})->with([
    ['Pieluchomajtki dla dorosłych zapinane na rzepy Seni Standard Plus Air', 'Seni Standard Plus Air pieluchomajtki dla dorosłych zapinane na rzepy'],
    ['Majtki chłonne Seni Active Basic zakładane jak bielizna', 'Seni Active Basic majtki chłonne zakładane jak bielizna'],
    ['Majtki mocujące dla mężczyzn Seni FIX COMFORT MAN 2 szt.', 'Seni FIX COMFORT MAN majtki mocujące dla mężczyzn 2 szt.'],
    ['Odżywczy krem do stóp Seni Care 7% mocznik', 'Seni Care odżywczy krem do stóp 7% mocznik'],
    ['Wkładki urologiczne dla mężczyzn Seni Man Level 3 Extra Black', 'Seni Man Level 3 Extra Black wkładki urologiczne dla mężczyzn'],
    ['Wkładki urologiczne dla mężczyzn Seni Man Extra Level 4 Plus', 'Seni Man Extra Level 4 Plus wkładki urologiczne dla mężczyzn'],
    ['Chusteczki nawilżane do higieny intymnej Seni Care Inti+', 'Seni Care Inti+ chusteczki nawilżane do higieny intymnej'],
    ['Pieluchy anatomiczne dla mężczyzn Seni San for Men', 'Seni San for Men pieluchy anatomiczne dla mężczyzn'],
    ['Profesjonalny neutralizator zapachu Seni Fresh 500 ml', 'Seni Fresh profesjonalny neutralizator zapachu 500 ml'],
    ['Pas przedłużający do pieluchomajtek Seni Optima 10 szt.', 'Seni Optima pas przedłużający do pieluchomajtek 10 szt.'],
    ['Seni Super Quatro pieluchomajtki dla dorosłych zapinane na rzepy', null],
    ['Super Seni pieluchomajtki dla dorosłych zapinane na rzepy 1 szt.', null],
]);

it('renames Seni products and rolls the names back', function (): void {
    $product = feedbackVariant('Pieluchy anatomiczne Seni San Classic Uni', 4080)->product;

    $this->artisan('seni:model-first-names', ['--write' => true])
        ->expectsOutputToContain('RENAMES=1')
        ->expectsOutputToContain('RENAMED=1')
        ->assertSuccessful();

    expect($product->fresh()->name)->toBe('Seni San Classic Uni pieluchy anatomiczne');

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();
    $this->artisan('seni:model-first-names', ['--rollback' => $backup])->assertSuccessful();

    expect($product->fresh()->name)->toBe('Pieluchy anatomiczne Seni San Classic Uni');
});

function feedbackCategory(string $name, ?Category $parent = null): Category
{
    return Category::query()->create([
        'parent_id' => $parent?->id,
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'status' => CategoryStatus::ACTIVE,
    ]);
}

function feedbackVariant(string $name, ?int $grossAmount, ?Category $category = null, string $source = 'test', ?string $externalId = null): ProductVariant
{
    $product = Product::query()->create([
        'external_source' => $source,
        'external_id' => $externalId ?? (string) str()->uuid(),
        'name' => $name,
        'slug' => str($name)->slug()->limit(60, '')->append('-'.str()->random(6))->lower()->toString(),
        'status' => ProductStatus::ACTIVE,
    ]);

    if ($category !== null) {
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'SKU-'.$product->id,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => $grossAmount === null ? null : VatRate::VAT_8->netFromGross($grossAmount),
        'price_gross_amount' => $grossAmount,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ])->load('product');
}
