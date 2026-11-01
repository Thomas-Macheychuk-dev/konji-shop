<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Seo\ProductMetaDescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('recognises weak meta descriptions', function (?string $meta, bool $weak): void {
    expect(ProductMetaDescription::isWeak($meta))->toBe($weak);
})->with([
    'empty' => [null, true],
    'short' => ['Orteza stopy ze stabilizatorem stawu skokowego.', true],
    'supplier breadcrumb' => ['Vermeiren Sprzęt przeciwodleżynowy Poduszki przeciwodleżynowe VICAIR ACTIVE O2', true],
    'supplier shop' => ['Sklep RelaxSan oferuje Pończochy przeciwżylakowe do paska 280 DEN 2 klasa ucisku 22-27 mmHg.', true],
    'shop medi' => ['Odkryj pończochy Mediven Forte w Shop Medi. Trwałe, wygodne, wykonane z dzianiny o wysokiej gęstości.', true],
    'boilerplate' => ['bluza kosmetyczna damska ze spandexem MIA. Sprawdź opis i parametry produktu w Ortezka.pl.', true],
    'good' => ['Poduszka przeciwodleżynowa z komorami powietrznymi. Pokrowiec zdejmowany, do prania w 60°C, dostępne cztery rozmiary.', false],
]);

it('builds whole sentences from the product description without headings or tables', function (): void {
    $html = '<h2>VICAIR ACTIVE O2</h2><p>Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.</p>'
        .'<table><tr><td>Waga</td><td>1,2 kg</td></tr></table><p>Dostępna w czterech rozmiarach dopasowanych do szerokości siedziska wózka.</p>';

    $meta = ProductMetaDescription::build($html, null, 'VICAIR ACTIVE O2');

    expect($meta)->toBe('Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.')
        ->and(mb_strlen($meta))->toBeLessThanOrEqual(ProductMetaDescription::MAX_LENGTH)
        ->and($meta)->not->toContain('Waga');
});

it('removes supplier shop wording and cuts a single long sentence at a word boundary', function (): void {
    $long = '<p>Sklep RelaxSan oferuje pończochy przeciwżylakowe do paska wykonane z miękkiej przędzy z mikrofibry, która zapewnia stopniowany ucisk od kostki aż do uda przez cały dzień noszenia bez uczucia ściskania.</p>';
    $meta = ProductMetaDescription::build($long, null, 'Pończochy');

    expect($meta)->toStartWith('Pończochy przeciwżylakowe do paska')
        ->and($meta)->toEndWith('…')
        ->and(mb_strlen($meta))->toBeLessThanOrEqual(ProductMetaDescription::MAX_LENGTH)
        ->and($meta)->not->toContain('Sklep');
});

it('returns nothing when the description is too thin to describe the product', function (): void {
    expect(ProductMetaDescription::build('<p>Orteza kolana.</p>', 'Orteza.', 'Orteza kolana'))->toBeNull();
});

it('previews, writes with a backup and rolls back weak product meta descriptions', function (): void {
    Storage::fake('local');
    $weak = Product::query()->forceCreate([
        'name' => 'VICAIR ACTIVE O2', 'slug' => 'vicair-active-o2', 'status' => ProductStatus::ACTIVE,
        'seo_description' => 'Vermeiren Sprzęt przeciwodleżynowy Poduszki przeciwodleżynowe VICAIR ACTIVE O2',
        'description' => '<p>Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.</p>',
    ]);
    $good = Product::query()->forceCreate([
        'name' => 'Good', 'slug' => 'good', 'status' => ProductStatus::ACTIVE,
        'seo_description' => 'Poduszka przeciwodleżynowa z komorami powietrznymi. Pokrowiec zdejmowany, do prania w 60°C, dostępne cztery rozmiary.',
        'description' => '<p>Inny opis produktu, który nie powinien zostać użyty, bo meta jest już dobra.</p>',
    ]);
    $thin = Product::query()->forceCreate([
        'name' => 'Thin', 'slug' => 'thin', 'status' => ProductStatus::ACTIVE, 'seo_description' => null,
        'description' => '<p>Krótki opis.</p>', 'external_source' => 'eldan',
    ]);
    Product::query()->forceCreate([
        'name' => 'Archived', 'slug' => 'archived', 'status' => ProductStatus::ARCHIVED, 'seo_description' => null,
        'description' => '<p>Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.</p>',
    ]);

    expect(Artisan::call('seo:product-meta-descriptions'))->toBe(0)
        ->and(Artisan::output())->toContain('WEAK_META=2')->toContain('REBUILT_META=1')->toContain('NO_SOURCE eldan=1')->toContain('MODE=READ_ONLY');

    expect(Artisan::call('seo:product-meta-descriptions', ['--write' => true]))->toBe(0);
    $output = Artisan::output();
    $backup = trim(str($output)->after('BACKUP=')->before("\n"));
    expect($output)->toContain('WRITTEN_META=1')
        ->and($weak->fresh()->seo_description)->toBe('Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.')
        ->and($good->fresh()->seo_description)->toStartWith('Poduszka przeciwodleżynowa')
        ->and($thin->fresh()->seo_description)->toBeNull();

    expect(Artisan::call('seo:product-meta-descriptions', ['--rollback' => $backup]))->toBe(0)
        ->and(Artisan::output())->toContain('RESTORED_META=1 OF 1')
        ->and($weak->fresh()->seo_description)->toBe('Vermeiren Sprzęt przeciwodleżynowy Poduszki przeciwodleżynowe VICAIR ACTIVE O2');
});
