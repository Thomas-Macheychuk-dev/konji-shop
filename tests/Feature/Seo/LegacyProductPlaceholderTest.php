<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('permanently redirects the indexed legacy Q5 URL to its durable placeholder', function (): void {
    $this
        ->get('/wozek-inwalidzki-o-napedzie-elektrycznym-q5-id-2256')
        ->assertStatus(301)
        ->assertRedirect('/products/wozek-inwalidzki-o-napedzie-elektrycznym-q5');
});

it('serves the Q5 placeholder as an indexable canonical product page without checkout controls', function (): void {
    $canonical = route('legacy-products.placeholder', [
        'slug' => 'wozek-inwalidzki-o-napedzie-elektrycznym-q5',
    ]);

    $response = $this
        ->get($canonical)
        ->assertOk()
        ->assertSee('Wózek inwalidzki o napędzie elektrycznym Q5 - MDH')
        ->assertSee('Produkt obecnie niedostępny')
        ->assertSee('<meta name="robots" content="index, follow">', false)
        ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
        ->assertSee('"@type": "Product"', false)
        ->assertSee('"url": "'.$canonical.'"', false)
        ->assertDontSee('Dodaj do koszyka')
        ->assertDontSee('Zamówienie z obowiązkiem zapłaty')
        ->assertDontSee('"@type": "Offer"', false);

    expect($response->getContent())
        ->not->toContain('20 000')
        ->not->toContain('20000');
});

it('does not let the placeholder route swallow unrelated product slugs', function (): void {
    $this
        ->get('/products/this-is-not-a-legacy-placeholder')
        ->assertNotFound();
});

it('includes the Q5 placeholder canonical URL in the sitemap', function (): void {
    $canonical = route('legacy-products.placeholder', [
        'slug' => 'wozek-inwalidzki-o-napedzie-elektrycznym-q5',
    ]);

    $this
        ->get(route('sitemap'))
        ->assertOk()
        ->assertSee($canonical, false);
});
