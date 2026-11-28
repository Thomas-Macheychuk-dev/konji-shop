<?php

use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Peruka\PerukaFilterCrawler;
use App\Services\Wigs\WigFilterAttributes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('tells the colour group from a wig name, ignoring the roots', function (string $name, ?string $colour): void {
    expect(WigFilterAttributes::colourFromName($name))->toBe($colour);
})->with([
    ['Peruka męska z włosów syntetycznych siwa STEVEN M56s', 'Siwy'],
    ['Peruka męska popielaty brąz z efektem siwizny STEVEN M36s', 'Siwy'],
    ['Peruka syntetyczna krótka fryzura blond z ciemnym brązowym odrostem ELM', 'Jasny blond'],
    ['Peruka syntetyczna ciemny brąz z odrostem TARANTELA', 'Ciemny brąz'],
    ['Peruka syntetyczna ciepły karmelowy brąz CREAM', 'Brąz'],
    ['Peruka damska ciemny blond CHERRY', 'Ciemny blond'],
    ['Peruka syntetyczna karmelowo-miodowy blond GARDEN', 'Ciemny blond'],
    ['Peruka syntetyczna termoodporna ruda ALDER', 'Rudy'],
    ['Peruka naturalna remy winny burgund APPEAL MONO', 'Kasztan i burgund'],
    ['Peruka syntetyczna kasztanowy brąz PEONY', 'Kasztan i burgund'],
    ['Peruka syntetyczna, kolor jasny blond, krótkie włosy - CLAUDIA', 'Jasny blond'],
    ['Peruka czarna JET', 'Czarny'],
    ['Głowa perukarska płócienna', null],
]);

it('reads which products each peruka.pl filter value lists', function (): void {
    $chosen = null;
    $page = function (array $numbers, int $pageNumber, bool $filtered): string {
        $tiles = implode('', array_map(fn (int $n): string => '<div class="product"><a class="product_name" href="/wig-'.$n.'.html">Wig '.$n.'</a><a href="/wig-'.$n.'.html"><img></a></div>', $numbers));
        $pagination = '<ul class="pagination"><li><a href="#">«</a></li><li><a href="/category/peruki/2/long/priority/asc/0'.($filtered ? '?filter=1' : '').'">2</a></li></ul>';

        return '<html><body>
            <form method="post" action="/product/filter?category_id=5" class="product-attribute-filter-container filter-left">
                <input type="hidden" name="type" value="attr">
                <span class="colors-filter-item"><button type="button"></button><input class="sr-only" type="checkbox" name="app_product_filter[1][103]" value="103"></span>
                <span class="colors-filter-item"><button type="button"></button><input class="sr-only" type="checkbox" name="app_product_filter[1][106]" value="106"></span>
                <div class="filter-title">Struktura włosów</div>
                <label><input type="checkbox" name="app_product_filter[17][88]" value="88"> Kręcone </label>
                <label><input type="checkbox" name="app_product_filter[15][84]" value="84"> Tak</label>
            </form>
            <form method="post" action="/product/filter?category_id=5" class="product-attribute-filter-container filter-left">
                <label><input type="checkbox" name="app_product_filter[17][88]" value="88"> Kręcone (mobile)</label>
            </form>'.$tiles.$pagination.'</body></html>';
    };

    $referers = [];

    Http::fake(function (Request $request) use (&$chosen, &$referers, $page) {
        if ($request->method() === 'POST') {
            $referers[] = $request->header('Referer')[0] ?? null;
            $chosen = preg_match('/app_product_filter%5B(\d+)%5D%5B(\d+)%5D/', $request->body(), $match) === 1 ? $match[1].'/'.$match[2] : null;

            return Http::response('{"status":"ok"}');
        }

        $filtered = str_contains($request->url(), 'filter=1');
        $numbers = ! $filtered ? range(1, 31) : match ($chosen) {
            '1/103' => [1, 2],
            '17/88' => [3],
            default => range(1, 31), // The long-hair filter "does not take".
        };
        $pageNumber = str_contains($request->url(), '/peruki/2/') ? 2 : 1;

        return Http::response($page(array_slice($numbers, ($pageNumber - 1) * 16, 16), $pageNumber, $filtered));
    });

    $result = app(PerukaFilterCrawler::class)->withDelay(0)->crawlCategory('peruki', [1, 17]);

    expect($result['category_id'])->toBe(5)
        ->and($result['all'])->toHaveCount(31)
        ->and($result['filters'][1]['values'][103]['urls'])->toBe(['https://www.peruka.pl/wig-1.html', 'https://www.peruka.pl/wig-2.html'])
        ->and($result['filters'][1]['values'][103]['label'])->toBeNull()
        ->and($result['filters'][1]['values'][106]['urls'])->toBeNull()
        ->and($result['failed'])->toHaveKey('peruki 1/106')
        ->and($result['filters'][17]['values'][88])->toMatchArray(['label' => 'Kręcone', 'urls' => ['https://www.peruka.pl/wig-3.html']])
        // "Linia rozmyta" is read from the form but not fetched.
        ->and($result['filters'][15]['values'][84]['urls'])->toBeNull()
        // The filter is cleared at the end.
        ->and($chosen)->toBeNull()
        // peruka.pl answers 500 to a filter POST without the category page as Referer.
        ->and(array_unique($referers))->toBe(['https://www.peruka.pl/category/peruki']);
});

it('previews, writes and rolls back the wig filter attributes', function (): void {
    Storage::fake('local');

    $steven14 = wigProduct('peruka', 'Peruka męska z włosów syntetycznych brąz STEVEN M14s', 'https://www.peruka.pl/steven-m14s-peruka-syntetyczna.html', 'steven');
    $steven56 = wigProduct('peruka', 'Peruka męska z włosów syntetycznych siwa STEVEN M56s', 'https://www.peruka.pl/steven-m56s-peruka-syntetyczna.html', 'steven');
    $cherry = wigProduct('peruka', 'Peruka damska ciemny blond CHERRY', 'https://www.peruka.pl/cherry-peruka-syntetyczna.html', null);
    $accessory = wigProduct('peruka', 'Głowa perukarska', 'https://www.peruka.pl/glowa-perukarska.html', null);
    $claudia = wigProduct('twojaperuka', 'Peruka syntetyczna, kolor jasny blond, krótkie włosy - CLAUDIA', 'https://twojaperuka.pl/claudia', null);
    $brazowa = wigProduct('twojaperuka', 'Peruka syntetyczna, długie włosy - LENA', 'https://twojaperuka.pl/lena', null);

    $value = fn (?string $label, int $position, array $urls): array => ['label' => $label, 'position' => $position, 'urls' => $urls];
    Storage::disk('local')->put('crawl.json', json_encode([
        'peruka' => [
            'peruki' => [
                'category_id' => 5,
                'all' => [],
                'filters' => [
                    1 => ['values' => [103 => $value(null, 0, ['https://www.peruka.pl/steven-m14s-peruka-syntetyczna.html', 'https://www.peruka.pl/cherry-peruka-syntetyczna.html'])]],
                    12 => ['values' => [55 => $value(null, 3, ['https://www.peruka.pl/steven-m56s-peruka-syntetyczna.html'])]],
                    7 => ['values' => [36 => $value('Włosy syntetyczne', 9, ['https://www.peruka.pl/steven-m14s-peruka-syntetyczna.html', 'https://www.peruka.pl/gone.html'])]],
                    15 => ['values' => [84 => $value('Tak', 12, ['https://www.peruka.pl/cherry-peruka-syntetyczna.html'])]],
                ],
                'failed' => [],
            ],
        ],
        'twojaperuka' => [
            '/pl/c/Peruki-krotkie/53' => ['https://twojaperuka.pl/claudia'],
            '/pl/c/Peruki-dlugie/55' => ['https://twojaperuka.pl/lena/'],
            '/brazowe_peruki_twoja_peruka_pl' => ['https://twojaperuka.pl/lena'],
        ],
        'failed' => [],
    ]));

    $this->artisan('wigs:filters', ['--from' => 'crawl.json'])
        ->expectsOutputToContain('PRODUCTS=5 CHANGES=5 COLOUR_FROM_NAME=2 UNMATCHED_URLS=1')
        ->expectsOutputToContain('Długość fryzury: Krótkie=4, Długie=1')
        ->expectsOutputToContain('UNMATCHED https://www.peruka.pl/gone.html')
        ->expectsOutputToContain('UNKNOWN_FILTER peruki 15')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect(Attribute::query()->where('slug', WigFilterAttributes::LENGTH)->exists())->toBeFalse();

    $this->artisan('wigs:filters', ['--from' => 'crawl.json', '--write' => true])
        ->expectsOutputToContain('WRITTEN=5')
        ->assertSuccessful();

    $labels = fn (Product $product): array => $product->attributeValues()->with('attribute')->get()
        ->mapWithKeys(fn ($value): array => [$value->attribute->slug => $value->value])->sortKeys()->all();

    // The model's length and hair type reach both Steven colours; the colour stays each product's own.
    expect($labels($steven14))->toBe(['dlugosc-fryzury' => 'Krótkie', 'kolor-wlosow' => 'Brąz', 'rodzaj-wlosa' => 'Włosy syntetyczne'])
        ->and($labels($steven56))->toBe(['dlugosc-fryzury' => 'Krótkie', 'kolor-wlosow' => 'Siwy', 'rodzaj-wlosa' => 'Włosy syntetyczne'])
        ->and($labels($cherry))->toBe(['dlugosc-fryzury' => 'Krótkie', 'kolor-wlosow' => 'Ciemny blond'])
        ->and($labels($accessory))->toBe([])
        ->and($labels($claudia))->toBe(['dlugosc-fryzury' => 'Krótkie', 'kolor-wlosow' => 'Jasny blond'])
        // No colour in the name: the colour subcategory decides.
        ->and($labels($brazowa))->toBe(['dlugosc-fryzury' => 'Długie', 'kolor-wlosow' => 'Brąz'])
        ->and(Attribute::query()->where('slug', WigFilterAttributes::LENGTH)->value('filter_position'))->toBe(10);

    $this->artisan('wigs:filters', ['--from' => 'crawl.json'])
        ->expectsOutputToContain('PRODUCTS=5 CHANGES=0')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('wigs:filters', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED=5 OF 5')
        ->assertSuccessful();

    expect($labels($steven14))->toBe([])
        ->and(Storage::disk('local')->exists($backup.'.rolled-back'))->toBeTrue();
});

it('filters a wig category by the wig attributes', function (): void {
    $category = Category::query()->create(['slug' => 'peruki', 'name' => 'Peruki', 'status' => CategoryStatus::ACTIVE]);
    $orthoses = Category::query()->create(['slug' => 'ortezy', 'name' => 'Ortezy', 'status' => CategoryStatus::ACTIVE]);
    $attributes = app(WigFilterAttributes::class);
    $length = $attributes->attribute(WigFilterAttributes::LENGTH);
    $colour = $attributes->attribute(WigFilterAttributes::COLOUR);

    $wig = function (string $name, array $values) use ($category): Product {
        $product = listedWig($name, $category);
        $product->attributeValues()->attach(array_map(fn ($value) => $value->id, $values));

        return $product;
    };

    $short = $attributes->value($length, 'Krótkie');
    $long = $attributes->value($length, 'Długie');
    $grey = $attributes->value($colour, 'Siwy');
    $blonde = $attributes->value($colour, 'Jasny blond');

    $wig('Steven siwy', [$short, $grey]);
    $wig('Cherry blond', [$short, $blonde]);
    $wig('Tarantela blond', [$long, $blonde]);
    listedWig('Orteza kolana', $orthoses);

    $names = fn (string $query): array => collect($this->get('/categories/peruki?'.$query)->viewData('products')->items())->pluck('name')->sort()->values()->all();

    expect($names('dlugosc-fryzury[]=krotkie'))->toBe(['Cherry blond', 'Steven siwy'])
        ->and($names('dlugosc-fryzury[]=krotkie&kolor-wlosow[]=jasny-blond'))->toBe(['Cherry blond'])
        ->and($names('kolor-wlosow[]=jasny-blond&kolor-wlosow[]=siwy'))->toBe(['Cherry blond', 'Steven siwy', 'Tarantela blond'])
        ->and($names('dlugosc-fryzury[]=nie-ma'))->toBe([]);

    $this->get('/categories/peruki?kolor-wlosow[]=siwy')
        ->assertOk()
        ->assertSeeInOrder(['Długość fryzury', 'Krótkie', 'Długie', 'Kolor włosów', 'Jasny blond', 'Siwy'])
        ->assertSee('name="kolor-wlosow[]" value="siwy" checked', false)
        ->assertSee('background-color: #bdbdbd', false)
        // Length stays a dropdown; hair colour, whose values all have swatches, is a row of chips below.
        ->assertSeeInOrder(['<summary', 'Długość fryzury', 'Pokaż wyniki', '<fieldset', 'Kolor włosów', 'Jasny blond'], false)
        ->assertSee('<meta name="robots" content="noindex, follow">', false);

    // Listings without wigs show no wig filters.
    $this->get('/categories/ortezy')->assertOk()->assertDontSee('Długość fryzury');
});

function wigProduct(string $source, string $name, string $url, ?string $colourGroup): Product
{
    static $sequence = 0;
    $sequence++;

    return Product::query()->create([
        'name' => $name,
        'slug' => 'wig-'.$sequence,
        'status' => ProductStatus::ACTIVE,
        'external_source' => $source,
        'external_id' => $source.'-'.$sequence,
        'external_parent_sku' => $url,
        'colour_group' => $colourGroup,
    ]);
}

function listedWig(string $name, Category $category): Product
{
    static $sequence = 0;
    $sequence++;

    $product = Product::query()->create([
        'name' => $name,
        'slug' => 'listed-wig-'.$sequence,
        'status' => ProductStatus::ACTIVE,
        'published_at' => now()->subDays($sequence),
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'WIG-'.$sequence,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => VatRate::VAT_8->netFromGross(100000),
        'price_gross_amount' => 100000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    return $product;
}
