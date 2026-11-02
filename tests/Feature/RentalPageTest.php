<?php

use App\Mail\RentalEnquiryMail;
use App\Models\RentalItem;
use Database\Seeders\RentalItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('seeds the rental offer once and keeps later edits on rerun', function (): void {
    $this->seed(RentalItemSeeder::class);

    expect(RentalItem::query()->count())->toBe(count(RentalItemSeeder::ITEMS))
        ->and(RentalItem::query()->pluck('group')->unique()->diff(array_keys(RentalItem::GROUPS)))->toBeEmpty();

    RentalItem::query()->where('slug', 'rollator')->update(['monthly_min' => 40]);
    $this->seed(RentalItemSeeder::class);

    expect(RentalItem::query()->count())->toBe(count(RentalItemSeeder::ITEMS))
        ->and(RentalItem::query()->where('slug', 'rollator')->value('monthly_min'))->toBe(40);

    foreach (RentalItem::query()->pluck('image_path') as $path) {
        expect(public_path($path))->toBeFile();
    }
});

it('shows the rental offer grouped with deposits, monthly fees and phone links', function (): void {
    $this->seed(RentalItemSeeder::class);
    RentalItem::query()->where('slug', 'kule-pachowe')->update(['is_active' => false]);

    $response = $this->get(route('rental.index'))->assertOk();

    $response->assertSeeInOrder(array_values(RentalItem::GROUPS))
        ->assertSee('Łóżko rehabilitacyjne manualne')
        ->assertSee('Bez kaucji')
        ->assertSee('100–150 zł')
        ->assertSee('35–50 zł')
        ->assertSee('Możliwy zakup z dofinansowaniem NFZ')
        ->assertSee('href="tel:+48604895717"', false)
        ->assertDontSee('Kule pachowe');

    // The enquiry panel (phones, address) sits in the page header, before the equipment list.
    $response->assertSeeInOrder(['<h1', 'Zapytaj o dostępność', 'href="tel:+48697595045"', 'Bolesława Prusa 20', 'id="beds"'], false);
    expect(substr_count($response->getContent(), 'Zapytaj o dostępność'))->toBe(1);
    // Section links follow the header card, ahead of the terms and the enquiry form.
    $response->assertSeeInOrder(['Zapytaj o dostępność', 'aria-label="Rodzaje sprzętu"', 'href="#beds"', 'href="#mobility"', 'Dostawa pod wskazany adres', 'id="zapytanie"', 'id="beds"'], false);

    expect($response->viewData('seoTitle'))->toBe('Wypożyczalnia sprzętu medycznego Poznań')
        ->and(substr_count($response->getContent(), '<h1'))->toBe(1);
});

it('redirects the legacy rental page and lists the new one in the sitemap', function (): void {
    $this->get('/content/11-wypozyczalnia-sprzetu-medycznego')
        ->assertMovedPermanently()
        ->assertRedirect('/wypozyczalnia-sprzetu-medycznego');

    $this->get('/sitemap.xml')->assertOk()->assertSee(route('rental.index'), false);
});

it('links the rental page from the top menu, before the customer information links', function (): void {
    $this->get(route('home'))->assertOk()->assertSeeInOrder([
        'href="'.route('rental.index').'"',
        'Wypożyczalnia sprzętu medycznego',
        'Refundacje NFZ',
        'Odstąp od umowy',
        'Dostawa i płatności',
        'Kontakt',
    ], false);
});

it('emails a rental enquiry to the shop with the visitor as reply-to', function (): void {
    Mail::fake();
    config()->set('rental.enquiry_email', 'wypozyczalnia@example.test');
    $this->seed(RentalItemSeeder::class);

    $this->post(route('rental.enquiry'), [
        'name' => 'Anna Kowalska',
        'email' => 'anna@example.test',
        'phone' => '+48 600 100 200',
        'items' => ['lozko-rehabilitacyjne-manualne'],
        'period' => '3 miesiące',
        'city' => 'Poznań',
        'message' => 'Czy łóżko jest dostępne od przyszłego tygodnia?',
        'website' => '',
    ])->assertRedirect(route('rental.index').'#zapytanie')->assertSessionHas('rental_enquiry_sent');

    Mail::assertQueued(RentalEnquiryMail::class, function (RentalEnquiryMail $mail): bool {
        return $mail->hasTo('wypozyczalnia@example.test')
            && $mail->hasReplyTo('anna@example.test')
            && ! $mail->hasTo('anna@example.test')
            && $mail->items === ['Łóżko rehabilitacyjne manualne']
            && str_contains($mail->render(), 'Czy łóżko jest dostępne');
    });

    $this->withSession(['rental_enquiry_sent' => true])->get(route('rental.index'))
        ->assertSee('Dziękujemy, zapytanie zostało wysłane.');
});

it('rejects bots, unknown equipment and incomplete enquiries without sending anything', function (): void {
    Mail::fake();
    $this->seed(RentalItemSeeder::class);
    $valid = ['name' => 'Jan', 'email' => 'jan@example.test', 'message' => 'Proszę o kontakt w sprawie wózka.'];

    $this->post(route('rental.enquiry'), $valid + ['website' => 'http://spam.example'])->assertSessionHasErrors('website');
    $this->post(route('rental.enquiry'), $valid + ['items' => ['nie-ma-takiego']])->assertSessionHasErrors('items.0');
    $this->post(route('rental.enquiry'), ['name' => 'Jan', 'email' => 'nie-email', 'message' => 'krótko'])
        ->assertSessionHasErrors(['email', 'message']);

    Mail::assertNothingQueued();
});

it('throttles repeated enquiries from one visitor', function (): void {
    Mail::fake();
    $valid = ['name' => 'Jan', 'email' => 'jan@example.test', 'message' => 'Proszę o kontakt w sprawie wózka.'];

    foreach (range(1, 5) as $attempt) {
        $this->post(route('rental.enquiry'), $valid)->assertRedirect();
    }

    $this->post(route('rental.enquiry'), $valid)->assertTooManyRequests();
});

it('preselects the equipment from an item card link', function (): void {
    $this->seed(RentalItemSeeder::class);

    $this->get(route('rental.index', ['sprzet' => 'rollator']))
        ->assertOk()
        ->assertSee('<option value="rollator" selected>', false)
        ->assertSee('rel="nofollow"', false);
});

it('formats single prices and ranges', function (): void {
    $item = new RentalItem(['deposit_min' => 200, 'deposit_max' => 500, 'monthly_min' => 80, 'monthly_max' => null]);

    expect($item->depositLabel())->toBe('200–500 zł')
        ->and($item->monthlyLabel())->toBe('80 zł')
        ->and((new RentalItem(['monthly_min' => 80]))->depositLabel())->toBe('Bez kaucji');
});
