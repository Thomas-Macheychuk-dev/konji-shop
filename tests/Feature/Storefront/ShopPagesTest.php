<?php

use App\Enums\CategoryStatus;
use App\Mail\ContactMessageMail;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('shows a branded 404 page with search and category links', function (): void {
    $this->get('/to-nie-istnieje')
        ->assertNotFound()
        ->assertSee('Nie znaleźliśmy tej strony')
        ->assertSee('role="search"', false)
        ->assertSee(route('categories.index'), false)
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
});

it('lists every active top-level category on /categories', function (): void {
    $orthoses = Category::query()->create(['name' => 'Ortezy i stabilizatory', 'slug' => 'ortezy-i-stabilizatory', 'status' => CategoryStatus::ACTIVE]);
    Category::query()->create(['name' => 'Kolano', 'slug' => 'kolano', 'parent_id' => $orthoses->id, 'status' => CategoryStatus::ACTIVE]);
    Category::query()->create(['name' => 'Kompresja medyczna', 'slug' => 'kompresja', 'status' => CategoryStatus::ACTIVE]);
    Category::query()->create(['name' => 'Stary dział', 'slug' => 'stary', 'status' => CategoryStatus::ARCHIVED]);

    $this->get('/categories')
        ->assertOk()
        ->assertSee('Wszystkie działy sklepu')
        ->assertSee('Ortezy i stabilizatory')
        ->assertSee('Kompresja medyczna')
        ->assertDontSee('Stary dział')
        ->assertSee('<link rel="canonical" href="'.route('categories.index').'">', false);
});

it('sends a contact message to the shop with the visitor as reply-to', function (): void {
    Mail::fake();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->post(route('contact.message'), [
            '_token' => 'test-csrf-token',
            'name' => 'Anna Nowak',
            'email' => 'anna@example.com',
            'order_number' => '20261105-0001',
            'message' => 'Czy orteza ATTACK PRO jest dostępna w rozmiarze M lewa?',
        ])
        ->assertRedirect(route('legal.contact').'#formularz')
        ->assertSessionHas('contact_message_sent', true);

    Mail::assertQueued(ContactMessageMail::class, fn (ContactMessageMail $mail): bool => $mail->hasTo(config('legal.seller.email'))
        && $mail->hasReplyTo('anna@example.com')
        && $mail->orderNumber === '20261105-0001');

    $this->get(route('legal.contact'))->assertSee('Napisz do nas');
});

it('rejects contact messages from bots and with missing fields', function (): void {
    Mail::fake();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->post(route('contact.message'), ['_token' => 'test-csrf-token', 'name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Kup tanie linki SEO teraz', 'website' => 'https://spam.example'])
        ->assertSessionHasErrors('website');

    $this->withSession(['_token' => 'test-csrf-token'])
        ->post(route('contact.message'), ['_token' => 'test-csrf-token', 'name' => '', 'email' => 'nie-email', 'message' => 'krótko'])
        ->assertSessionHasErrors(['name', 'email', 'message']);

    Mail::assertNothingQueued();
});

it('registers without an address but requires the terms and a phone', function (): void {
    $payload = [
        'first_name' => 'Jan',
        'last_name' => 'Kowalski',
        'email' => 'jan@gmail.com',
        'phone_number' => '600100200',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ];

    $this->post(route('register.store'), $payload)->assertSessionHasErrors(['terms_accepted' => 'Zaakceptuj regulamin sklepu, aby założyć konto.']);
    $this->post(route('register.store'), [...$payload, 'terms_accepted' => '1', 'phone_number' => ''])->assertSessionHasErrors('phone_number');

    $this->post(route('register.store'), [...$payload, 'terms_accepted' => '1'])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'jan@gmail.com')->first())
        ->street->toBeNull()
        ->country->toBe('PL');
});

it('shows the terms checkbox and privacy note on the registration page', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('name="terms_accepted"', false)
        ->assertSee('Adres (opcjonalnie)')
        ->assertSee(route('legal.privacy'), false);
});
