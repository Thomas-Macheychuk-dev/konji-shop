<?php

use App\Models\RentalItem;
use App\Models\User;
use Database\Seeders\RentalItemContent;
use Database\Seeders\RentalItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function longRentalDescription(int $words = RentalItem::MIN_INDEXABLE_WORDS): string
{
    return '<h2>Dla kogo</h2><p>'.implode(' ', array_fill(0, $words, 'koncentrator')).'</p>';
}

function rentalItemWith(string $slug, array $attributes = []): RentalItem
{
    $item = RentalItem::query()->where('slug', $slug)->sole();
    $item->update($attributes);

    return $item->refresh();
}

beforeEach(function (): void {
    $this->seed(RentalItemSeeder::class);
});

it('shows an item page with prices, enquiry links, related items and product markup', function (): void {
    $item = rentalItemWith('koncentrator-tlenu-everflo', ['description' => '<p>Cichy koncentrator do domu.</p>', 'seo_title' => null]);

    $response = $this->get('/wypozyczalnia-sprzetu-medycznego/koncentrator-tlenu-everflo')
        ->assertOk()
        ->assertSee('<h1', false)
        ->assertSee('Cichy koncentrator tlenu Philips Respironics EverFlo')
        ->assertSee('Wydajność do 5 l/min.')
        ->assertSee('<p>Cichy koncentrator do domu.</p>', false)
        ->assertSee('500 zł')
        ->assertSee('130 zł')
        ->assertSee('tel:+48697595045', false)
        ->assertSee(route('rental.index', ['sprzet' => $item->slug]).'#zapytanie', false)
        ->assertSee('Koncentrator tlenu Aeroplus')
        ->assertSee('<link rel="canonical" href="'.$item->url().'">', false)
        ->assertSee('<title>Cichy koncentrator tlenu Philips Respironics EverFlo – wypożyczalnia Poznań', false)
        ->assertSee('"@type": "Product"', false)
        ->assertSee('"businessFunction": "http://purl.org/goodrelations/v1#LeaseOut"', false)
        ->assertSee('"unitCode": "MON"', false)
        ->assertSee('"@type": "BreadcrumbList"', false)
        ->assertSee('<meta property="og:image" content="'.$item->imageUrl().'">', false);

    expect($response->getContent())->not->toContain('Cichy koncentrator tlenu Philips Respironics EverFlo</a>');
});

it('keeps thin item pages out of the index until the description is long enough', function (): void {
    $item = rentalItemWith('rollator', ['description' => null]);

    $this->get($item->url())->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);
    $this->get(route('sitemap'))->assertOk()->assertDontSee($item->url(), false);

    $item->update(['description' => longRentalDescription(RentalItem::MIN_INDEXABLE_WORDS - 3)]);
    $this->get($item->url())->assertSee('<meta name="robots" content="noindex, follow">', false);

    $item->update(['description' => longRentalDescription()]);

    $this->get($item->url())->assertOk()->assertDontSee('name="robots"', false);
    $this->get(route('sitemap'))->assertOk()->assertSee('<loc>'.$item->url().'</loc>', false);

    $item->update(['is_indexable' => false]);

    $this->get($item->url())->assertSee('<meta name="robots" content="noindex, follow">', false);
    $this->get(route('sitemap'))->assertDontSee($item->url(), false);
});

it('seeds an indexable page text for every rental item', function (): void {
    expect(array_keys(RentalItemContent::ITEMS))->toEqualCanonicalizing(array_column(RentalItemSeeder::ITEMS, 0));

    foreach (RentalItem::query()->get() as $item) {
        expect($item->descriptionWordCount())->toBeGreaterThanOrEqual(RentalItem::MIN_INDEXABLE_WORDS, $item->slug)
            ->and(mb_strlen($item->seo_title))->toBeLessThanOrEqual(60, $item->slug)
            ->and(mb_strlen($item->seo_description))->toBeLessThanOrEqual(160, $item->slug)
            ->and($item->shouldBeIndexed())->toBeTrue($item->slug);
    }

    $content = collect(RentalItemContent::ITEMS);

    expect($content->pluck('seo_title')->unique())->toHaveCount($content->count())
        ->and($content->pluck('seo_description')->unique())->toHaveCount($content->count());

    $this->get(route('sitemap'))
        ->assertOk()
        ->assertSee(RentalItem::query()->where('slug', 'koncentrator-tlenu-everflo')->sole()->url(), false);
});

it('fills empty page texts on rerun without overwriting admin edits', function (): void {
    RentalItem::query()->where('slug', 'rollator')->update(['description' => null, 'seo_title' => null]);
    RentalItem::query()->where('slug', 'kule-pachowe')->update(['description' => '<p>Tekst z panelu.</p>', 'seo_description' => null]);

    $this->seed(RentalItemSeeder::class);

    $rollator = RentalItem::query()->where('slug', 'rollator')->sole();
    $crutches = RentalItem::query()->where('slug', 'kule-pachowe')->sole();

    expect($rollator->description)->toBe(RentalItemContent::ITEMS['rollator']['description'])
        ->and($rollator->seo_title)->toBe(RentalItemContent::ITEMS['rollator']['seo_title'])
        ->and($crutches->description)->toBe('<p>Tekst z panelu.</p>')
        ->and($crutches->seo_description)->toBe(RentalItemContent::ITEMS['kule-pachowe']['seo_description']);
});

it('returns 404 for hidden and unknown items', function (): void {
    $item = rentalItemWith('rollator', ['is_active' => false, 'description' => longRentalDescription()]);

    $this->get($item->url())->assertNotFound();
    $this->get('/wypozyczalnia-sprzetu-medycznego/nie-ma-takiego')->assertNotFound();
    $this->get(route('sitemap'))->assertDontSee($item->url(), false);
});

it('uses the SEO overrides when set', function (): void {
    $item = rentalItemWith('rollator', [
        'seo_title' => 'Rollator do wypożyczenia w Poznaniu',
        'seo_description' => 'Lekki rollator składany na miesiąc lub dłużej.',
    ]);

    $this->get($item->url())
        ->assertSee('<title>Rollator do wypożyczenia w Poznaniu', false)
        ->assertSee('<meta name="description" content="Lekki rollator składany na miesiąc lub dłużej.">', false);
});

it('builds a default meta description from the item data', function (): void {
    $item = rentalItemWith('wozek-inwalidzki-standardowy', ['seo_description' => null]);

    expect($item->seoDescription())
        ->toStartWith('Wypożycz: Wózek inwalidzki standardowy w Poznaniu.')
        ->toContain('35–50 zł')
        ->and(mb_strlen($item->seoDescription()))->toBeLessThanOrEqual(160);
});

it('links every card on the rental page to its item page and marks up the business', function (): void {
    $this->get(route('rental.index'))
        ->assertOk()
        ->assertSee(RentalItem::query()->where('slug', 'rollator')->sole()->url(), false)
        ->assertSee(RentalItem::query()->where('slug', 'podnosnik-pacjenta')->sole()->url(), false)
        ->assertSee('"@type": "MedicalBusiness"', false)
        ->assertSee('"postalCode": "60-820"', false)
        ->assertSee('"@type": "OfferCatalog"', false);

    $this->get(route('rental.index', ['sprzet' => 'rollator']))
        ->assertSee('<link rel="canonical" href="'.route('rental.index').'">', false);
});

it('dates the rental page in the sitemap by its latest change', function (): void {
    $this->travelTo(now()->addDay());
    $item = rentalItemWith('rollator', ['monthly_min' => 40]);

    $this->get(route('sitemap'))
        ->assertOk()
        ->assertSee('<lastmod>'.$item->updated_at->toAtomString().'</lastmod>', false);
});

it('lets admins write the description and SEO fields and names uploads after the item', function (): void {
    Storage::fake('public');
    $item = rentalItemWith('rollator', ['description' => null]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.rental.edit', $item))
        ->assertOk()
        ->assertSee('trix-editor', false)
        ->assertSee('opis ma 0 z wymaganych '.RentalItem::MIN_INDEXABLE_WORDS.' słów', false);

    $this->actingAs($admin)
        ->patch(route('admin.rental.update', $item), [
            'name' => $item->name,
            'group' => $item->group,
            'monthly_min' => '35',
            'deposit_min' => '150',
            'description' => longRentalDescription(),
            'seo_title' => 'Rollator Poznań',
            'seo_description' => 'Opis SEO rollatora.',
            'is_active' => '1',
            'is_indexable' => '1',
            'image' => UploadedFile::fake()->image('zdjecie.jpg'),
        ])
        ->assertSessionHasNoErrors();

    $item->refresh();

    expect($item->shouldBeIndexed())->toBeTrue()
        ->and($item->seo_title)->toBe('Rollator Poznań')
        ->and($item->image_path)->toMatch('#^rental/rollator-[a-z0-9]{6}\.jpg$#');

    $this->actingAs($admin)
        ->get(route('admin.rental.index'))
        ->assertSee('W Google');
});
