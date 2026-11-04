<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** @param  array<string, mixed>  $overrides */
function checkoutValidationPayload(array $overrides = []): array
{
    return [
        '_token' => 'test-csrf-token',
        'email' => 'guest@gmail.com',
        'phone' => '600100200',
        'shipping_first_name' => 'Jan',
        'shipping_last_name' => 'Kowalski',
        'shipping_address_line_1' => 'Prusa 20',
        'shipping_city' => 'Poznań',
        'shipping_postcode' => '60-820',
        'shipping_country_code' => 'PL',
        'delivery_provider' => DeliveryProvider::POLKURIER->value,
        'delivery_carrier' => DeliveryCarrier::INPOST->value,
        'delivery_service' => 'parcel_locker',
        'delivery_locker_code' => 'POZ01A',
        'billing_address_source' => 'same_as_shipping',
        'terms_accepted' => true,
        ...$overrides,
    ];
}

function postCheckout(array $overrides = []): TestResponse
{
    return test()
        ->withSession(['_token' => 'test-csrf-token'])
        ->post(route('checkout.place'), checkoutValidationPayload($overrides));
}

it('rejects a guest phone number that is not a phone number', function (string $phone): void {
    postCheckout(['phone' => $phone])
        ->assertSessionHasErrors([
            'phone' => 'Podaj poprawny numer telefonu, np. 600 100 200 lub +48 600 100 200.',
        ]);
})->with(['xx', '12345', '600 100 20a']);

it('accepts common guest phone number formats', function (string $phone): void {
    postCheckout(['phone' => $phone])->assertSessionDoesntHaveErrors('phone');
})->with(['600100200', '600 100 200', '600-100-200', '+48 600 100 200', '+49 30 1234567']);

it('rejects a malformed Polish postcode', function (string $postcode): void {
    postCheckout(['shipping_postcode' => $postcode])
        ->assertSessionHasErrors([
            'shipping_postcode' => 'Podaj kod pocztowy w formacie 00-000.',
        ]);
})->with(['abcde', '6-0820', '608200']);

it('accepts a Polish postcode typed without the dash', function (): void {
    postCheckout(['shipping_postcode' => '60820'])->assertSessionDoesntHaveErrors('shipping_postcode');
});

it('does not apply the Polish postcode format to other countries', function (): void {
    postCheckout(['shipping_postcode' => '10115', 'shipping_country_code' => 'DE'])
        ->assertSessionDoesntHaveErrors('shipping_postcode');
});

it('validates a separate Polish billing postcode', function (): void {
    postCheckout([
        'billing_address_source' => 'other',
        'billing_first_name' => 'Jan',
        'billing_last_name' => 'Kowalski',
        'billing_address_line_1' => 'Prusa 20',
        'billing_city' => 'Poznań',
        'billing_postcode' => 'abc',
        'billing_country_code' => 'PL',
    ])->assertSessionHasErrors(['billing_postcode' => 'Podaj kod pocztowy w formacie 00-000.']);
});

it('asks for a parcel locker without exposing internal values', function (): void {
    postCheckout(['delivery_locker_code' => null])
        ->assertSessionHasErrors(['delivery_locker_code' => 'Wybierz paczkomat.']);
});
