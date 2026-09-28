<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows published public legal pages without draft warnings', function (string $routeName, string $expectedText): void {
    $this
        ->get(route($routeName))
        ->assertOk()
        ->assertSee($expectedText)
        ->assertDontSee('Tekst roboczy')
        ->assertDontSee('[DO UZUPEŁNIENIA]');
})->with([
    ['legal.terms', 'Regulamin sklepu internetowego ORTEZKA.PL'],
    ['legal.privacy', 'Polityka prywatności'],
    ['legal.returns', 'Zwroty i odstąpienie od umowy'],
    ['legal.complaints', 'Reklamacje - niezgodność towaru z umową'],
    ['legal.delivery-payments', 'Dostawa i płatności'],
    ['legal.contact', 'Kontakt'],
    ['legal.cookie-policy', 'Polityka plików cookie'],
]);

it('publishes the approved legal release and customer PDF forms', function (): void {
    $this->get(route('legal.terms'))
        ->assertOk()
        ->assertSee('MAX-CORP sp. z o.o.')
        ->assertSee('60-820')
        ->assertSee('0000701386')
        ->assertSee('7811955475')
        ->assertSee('368620625')
        ->assertSee('282 450,00 zł')
        ->assertSee('28.09.2026')
        ->assertSee('Sam fakt, że produkt jest wyrobem medycznym albo ma kontakt z ciałem, nie wyłącza automatycznie prawa odstąpienia.')
        ->assertSee(asset(config('legal.documents.terms_pdf')), false);

    $this->get(route('legal.returns'))
        ->assertOk()
        ->assertSee('Złóż odstąpienie online')
        ->assertSee(asset(config('legal.documents.withdrawal_form_pdf')), false);

    $this->get(route('legal.complaints'))
        ->assertOk()
        ->assertSee('14 dni od dnia jej otrzymania')
        ->assertSee('Brak paragonu nie jest samodzielną podstawą')
        ->assertSee(asset(config('legal.documents.complaint_form_pdf')), false);

    expect(is_file(public_path(config('legal.documents.terms_pdf'))))->toBeTrue()
        ->and(is_file(public_path(config('legal.documents.withdrawal_form_pdf'))))->toBeTrue()
        ->and(is_file(public_path(config('legal.documents.complaint_form_pdf'))))->toBeTrue();
});

it('redirects legacy PrestaShop legal URLs permanently', function (string $legacyPath, string $routeName): void {
    $this->get($legacyPath)
        ->assertStatus(301)
        ->assertRedirect(route($routeName, [], false));
})->with([
    ['/content/7-regulamin', 'legal.terms'],
    ['/content/19-reklamacje-i-zwroty', 'legal.returns'],
    ['/content/9-warunki-dostawy', 'legal.delivery-payments'],
]);

it('shows legal footer links on the storefront', function (): void {
    $this
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('legal.terms'), false)
        ->assertSee(route('legal.privacy'), false)
        ->assertSee(route('legal.returns'), false)
        ->assertSee(route('legal.complaints'), false)
        ->assertSee(route('legal.delivery-payments'), false)
        ->assertSee(route('legal.contact'), false)
        ->assertSee(route('legal.cookie-policy'), false)
        ->assertSee('Regulamin', false)
        ->assertSee('Polityka prywatności')
        ->assertSee('Zwroty i odstąpienie od umowy', false)
        ->assertSee('Reklamacje', false)
        ->assertSee('Dostawa i płatności', false)
        ->assertSee('Polityka plików cookie');
});
