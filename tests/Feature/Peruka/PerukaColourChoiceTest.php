<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Peruka\PerukaColourLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('reads the colour strip and the own colour of a peruka.pl page', function (): void {
    $page = app(PerukaColourLinks::class)->parse(
        perukaColourPage('Peruka syntetyczna blond CALATA # 15/23/6R', ['calata-2' => '2', 'calata-14-16-8r-peruka-syntetyczna' => '14/16/8R', 'calata-15-23-6r-peruka-syntetyczna' => '15/23/6R']),
        'https://www.peruka.pl/calata-15-23-6r-peruka-syntetyczna.html',
    );

    expect($page)->toBe([
        'label' => '15/23/6R',
        'siblings' => [
            'https://www.peruka.pl/calata-2.html' => '2',
            'https://www.peruka.pl/calata-14-16-8r-peruka-syntetyczna.html' => '14/16/8R',
        ],
    ]);
});

it('links the colours of a wig model from one page per model, then rolls back', function (): void {
    Storage::fake('local');
    $calata = perukaColourProduct('calata-15-23-6r-peruka-syntetyczna');
    $calata2 = perukaColourProduct('calata-2');
    $calata14 = perukaColourProduct('calata-14-16-8r-peruka-syntetyczna');
    $solo = perukaColourProduct('turban-verbena-me12-flower');

    Http::preventStrayRequests();
    Http::fake([
        'www.peruka.pl/calata-15-23-6r-peruka-syntetyczna.html' => Http::response(perukaColourPage('CALATA # 15/23/6R', ['calata-2' => '2', 'calata-14-16-8r-peruka-syntetyczna' => '14/16/8R', 'calata-6-8-4r' => '6/8/4R'])),
        'www.peruka.pl/turban-verbena-me12-flower.html' => Http::response(perukaColourPage('VERBENA # ME12+B61', [])),
    ]);

    $this->artisan('peruka:link-colours', ['--delay-ms' => 0])
        ->expectsOutputToContain('PRODUCTS=4 PAGES=2 FAILED=0 GROUPS=1 LINKED=3 NO_LABEL=0 CHANGES=3')
        ->expectsOutputToContain('2, 14/16/8R, 15/23/6R')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    Http::assertSentCount(2);
    expect($calata->fresh()->colour_group)->toBeNull();

    $crawl = collect(Storage::disk('local')->files('catalogue-repairs'))->first(fn (string $path): bool => str_contains($path, 'crawl'));

    $this->artisan('peruka:link-colours', ['--from' => $crawl, '--write' => true])
        ->expectsOutputToContain('WRITTEN=3')
        ->assertSuccessful();

    Http::assertSentCount(2);

    $group = $calata->fresh()->colour_group;

    expect($group)->toStartWith('peruka-')
        ->and($calata2->fresh()->only(['colour_group', 'colour_label']))->toBe(['colour_group' => $group, 'colour_label' => '2'])
        ->and($calata14->fresh()->colour_label)->toBe('14/16/8R')
        ->and($calata->fresh()->colour_label)->toBe('15/23/6R')
        ->and($solo->fresh()->colour_group)->toBeNull();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->first(fn (string $path): bool => ! str_contains($path, 'crawl'));

    $this->artisan('peruka:link-colours', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED=3 OF 3')
        ->assertSuccessful();

    expect($calata2->fresh()->colour_group)->toBeNull();
});

it('offers the other colours of the model on the product page', function (): void {
    Storage::fake('public');
    $current = perukaColourProduct('calata-15-23-6r-peruka-syntetyczna', ['colour_group' => 'peruka-abc', 'colour_label' => '15/23/6R']);
    $other = perukaColourProduct('calata-2', ['colour_group' => 'peruka-abc', 'colour_label' => '2']);
    perukaColourProduct('calata-6-8-4r', ['colour_group' => 'peruka-abc', 'colour_label' => '6/8/4R', 'status' => ProductStatus::ARCHIVED]);

    foreach (['Hair-Up-Calata-2-900x900.jpg', '2.jpg'] as $position => $file) {
        ProductImage::query()->create([
            'product_id' => $other->id,
            'disk' => 'public',
            'path' => 'products/peruka/calata-2/gallery/'.$file,
            'source_url' => 'https://www.peruka.pl/media/products/d576/images/'.$file,
            'sort_order' => $position,
            'is_main' => $position === 0,
        ]);
    }

    $response = $this->get(route('products.show', $current->slug))->assertOk();
    $options = $response->viewData('colourOptions');

    expect(array_column($options, 'label'))->toBe(['2', '15/23/6R'])
        ->and(array_column($options, 'is_current'))->toBe([false, true])
        ->and($options[0]['url'])->toBe(route('products.show', 'calata-2'))
        ->and($options[0]['image_url'])->toEndWith('/2.jpg');

    $response->assertSee('Wybierz kolor')
        ->assertSee(route('products.show', 'calata-2'), false)
        ->assertDontSee(route('products.show', 'calata-6-8-4r'), false);
});

it('shows no colour choice for a product without colours', function (): void {
    $product = perukaColourProduct('turban-verbena-me12-flower');

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertDontSee('Wybierz kolor');
});

/** @param  array<string, mixed>  $attributes */
function perukaColourProduct(string $slug, array $attributes = []): Product
{
    return Product::query()->create($attributes + [
        'name' => 'Peruka '.$slug,
        'slug' => $slug,
        'status' => ProductStatus::ACTIVE,
        'external_source' => 'peruka',
        'external_id' => $slug,
        'external_parent_sku' => 'https://www.peruka.pl/'.$slug.'.html',
    ]);
}

/** @param  array<string, string>  $colours */
function perukaColourPage(string $heading, array $colours): string
{
    $tiles = '';

    // Peruka.pl's slider repeats the first tiles as clones.
    foreach ([...$colours, ...$colours] as $slug => $label) {
        $tiles .= '<div class="slick-slide"><div class="img"><a href="/'.$slug.'.html"><img src="/media/products/x/images/thumbnail/gallery_'.$label.'.jpg"></a></div>'
            .'<a href="/'.$slug.'.html"><br>'.$label.'</a></div>';
    }

    return '<html><head><title>'.$heading.' | Peruka.pl</title></head><body><h1>'.$heading.'</h1>'
        .($colours !== [] ? '<div id="product-colors"><p>Wybierz kolor:</p><div class="row">'.$tiles.'</div></div>' : '')
        .'</body></html>';
}
