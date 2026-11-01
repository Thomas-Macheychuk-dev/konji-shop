<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Seo\SupplierTitleSuffix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps the brand and drops supplier site branding', function (string $title, string $expected): void {
    expect(SupplierTitleSuffix::normalize($title))->toBe($expected);
})->with([
    ['VICAIR ACTIVE O2 | Vermeiren Polska', 'VICAIR ACTIVE O2 | Vermeiren'],
    ['ATTACK OA | Reh4Mat - Wytwórca nowoczesnych przedmiotów ortopedycznych', 'ATTACK OA | Reh4Mat'],
    ['Pończochy 280 DEN | Sklep RelaxSan', 'Pończochy 280 DEN | RelaxSan'],
    ['BP B3 AFIB - Microlife AG', 'BP B3 AFIB | Microlife'],
    ['Stabilizator rzepki Zamst JK-2 - Zamst Polska', 'Stabilizator rzepki Zamst JK-2 | Zamst'],
    ['mediven forte | medi sklep internetowy', 'mediven forte | medi'],
    ['Koszulka polo | Błękitny | Sklep z odzieżą medyczną Warszawa Apolonia', 'Koszulka polo | Błękitny | Apolonia'],
    ['Orteza - NOVICARE - Dystrybutor Specjalistycznych Produktów Ortopedycznych', 'Orteza | NOVICARE'],
    ['Turban VERBENA | Peruka.pl', 'Turban VERBENA'],
    ['Peruka IRIS - TwojaPeruka.pl', 'Peruka IRIS'],
    ['SEAL WHEELCHAIR - GaleriaZdrowia.pl', 'SEAL WHEELCHAIR'],
    ['Krzesło prysznicowe | Sklep', 'Krzesło prysznicowe'],
    ['Orteza AccuTex 2986 - Antar', 'Orteza AccuTex 2986 - Antar'],
    ['Wózek Maya | Timago', 'Wózek Maya | Timago'],
    ['Vermeiren Polska', 'Vermeiren Polska'],
]);

it('previews, writes with a backup and rolls back supplier title suffixes', function (): void {
    Storage::fake('local');
    $branded = Product::query()->forceCreate([
        'name' => 'VICAIR ACTIVE O2', 'slug' => 'vicair-active-o2', 'status' => ProductStatus::ACTIVE,
        'seo_title' => 'VICAIR ACTIVE O2 | Vermeiren Polska',
    ]);
    $edited = Product::query()->forceCreate([
        'name' => 'ATTACK OA', 'slug' => 'attack-oa', 'status' => ProductStatus::ACTIVE,
        'seo_title' => 'ATTACK OA | Reh4Mat - Wytwórca nowoczesnych przedmiotów ortopedycznych',
    ]);
    $brandOnly = Product::query()->forceCreate([
        'name' => 'Maya', 'slug' => 'maya', 'status' => ProductStatus::ACTIVE, 'seo_title' => 'Wózek Maya | Timago',
    ]);

    expect(Artisan::call('seo:supplier-title-suffixes'))->toBe(0)
        ->and(Artisan::output())->toContain('CHANGED_TITLES=2')->toContain('MODE=READ_ONLY')->toContain('1 | Timago');
    expect($branded->fresh()->seo_title)->toBe('VICAIR ACTIVE O2 | Vermeiren Polska');

    expect(Artisan::call('seo:supplier-title-suffixes', ['--write' => true]))->toBe(0);
    $output = Artisan::output();
    $backup = trim(str($output)->after('BACKUP=')->before("\n"));
    expect($output)->toContain('WRITTEN_TITLES=2')
        ->and($branded->fresh()->seo_title)->toBe('VICAIR ACTIVE O2 | Vermeiren')
        ->and($edited->fresh()->seo_title)->toBe('ATTACK OA | Reh4Mat')
        ->and($brandOnly->fresh()->seo_title)->toBe('Wózek Maya | Timago');
    Storage::disk('local')->assertExists($backup);

    // A title edited after the write is never overwritten by the rollback.
    $edited->forceFill(['seo_title' => 'ATTACK OA – orteza kolana'])->save();

    expect(Artisan::call('seo:supplier-title-suffixes', ['--rollback' => $backup]))->toBe(0)
        ->and(Artisan::output())->toContain('RESTORED_TITLES=1 OF 2')->toContain('SKIPPED_CHANGED_SINCE='.$edited->id)
        ->and($branded->fresh()->seo_title)->toBe('VICAIR ACTIVE O2 | Vermeiren Polska')
        ->and($edited->fresh()->seo_title)->toBe('ATTACK OA – orteza kolana');
});
