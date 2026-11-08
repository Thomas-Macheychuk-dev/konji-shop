<?php

use App\Console\Commands\RepairMobilexProducerNamesCommand;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

function mobilexProduct(string $name, string $slug, string $source = 'mobilex'): Product
{
    return Product::query()->create([
        'name' => $name,
        'slug' => $slug,
        'status' => ProductStatus::ACTIVE,
        'external_source' => $source,
        'external_id' => $slug,
    ]);
}

it('puts back the producer a cut name stands for', function (string $old, string $new): void {
    expect(RepairMobilexProducerNamesCommand::repairedName($old))->toBe($new);
})->with([
    ['ABERDEEN MAN MED Health and Fashion Shoes Italia S', 'ABERDEEN MAN MED Scholl'],
    ['BAHIA MED Health and Fashion Shoes Italia S – kolor różowy', 'BAHIA MED Scholl – kolor różowy'],
    ['Health and Fashion Shoes Italia S — ABERDEEN MAN MED', 'Scholl — ABERDEEN MAN MED'],
    ['Scholl BAHIA MED Health and Fashion Shoes Italia S', 'Scholl BAHIA MED'],
    ['Łóżko rehabilitacyjne Winncare Polska Sp', 'Łóżko rehabilitacyjne Winncare Polska'],
    // Whole legal forms and unrelated names stay.
    ['BAHIA MED Health and Fashion Shoes Italia S.p.A.', 'BAHIA MED Health and Fashion Shoes Italia S.p.A.'],
    ['Łóżko Winncare Polska Sp. z o.o.', 'Łóżko Winncare Polska Sp. z o.o.'],
    ['ABERDEEN MAN MED Scholl', 'ABERDEEN MAN MED Scholl'],
]);

it('previews without writing', function (): void {
    $product = mobilexProduct('ABERDEEN MAN MED Health and Fashion Shoes Italia S', 'aberdeen-man-med');

    $this->artisan('mobilex:repair-producer-names')
        ->expectsOutput('CUT_NAMES=1')
        ->expectsOutput('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($product->fresh()->name)->toBe('ABERDEEN MAN MED Health and Fashion Shoes Italia S')
        ->and(Storage::disk('local')->files('catalogue-repairs'))->toBe([]);
});

it('repairs Mobilex names only, idempotently, and rolls back', function (): void {
    $cut = mobilexProduct('ABERDEEN MAN MED Health and Fashion Shoes Italia S', 'aberdeen-man-med');
    $clean = mobilexProduct('BAHIA MED Scholl', 'bahia-med');
    $other = mobilexProduct('Klapki Health and Fashion Shoes Italia S', 'klapki', 'peruka');

    $this->artisan('mobilex:repair-producer-names', ['--write' => true])
        ->expectsOutput('WRITTEN_NAMES=1')
        ->assertSuccessful();

    expect($cut->fresh()->name)->toBe('ABERDEEN MAN MED Scholl')
        ->and($clean->fresh()->name)->toBe('BAHIA MED Scholl')
        ->and($other->fresh()->name)->toBe('Klapki Health and Fashion Shoes Italia S');

    $this->artisan('mobilex:repair-producer-names', ['--write' => true])
        ->expectsOutput('CUT_NAMES=0')
        ->expectsOutput('NOTHING_TO_WRITE')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('mobilex:repair-producer-names', ['--rollback' => $backup])
        ->expectsOutput('RESTORED_NAMES=1 OF 1')
        ->assertSuccessful();

    expect($cut->fresh()->name)->toBe('ABERDEEN MAN MED Health and Fashion Shoes Italia S');
});

it('leaves names edited after the repair alone on rollback', function (): void {
    $product = mobilexProduct('ABERDEEN MAN MED Health and Fashion Shoes Italia S', 'aberdeen-man-med');

    $this->artisan('mobilex:repair-producer-names', ['--write' => true])->assertSuccessful();
    $product->fresh()->update(['name' => 'ABERDEEN MAN MED Scholl – klapki męskie']);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('mobilex:repair-producer-names', ['--rollback' => $backup])
        ->expectsOutput('RESTORED_NAMES=0 OF 1')
        ->assertSuccessful();

    expect($product->fresh()->name)->toBe('ABERDEEN MAN MED Scholl – klapki męskie');
});
