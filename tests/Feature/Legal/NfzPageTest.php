<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('explains how to fill an NFZ order without offering form downloads', function (): void {
    $this
        ->get(route('legal.nfz'))
        ->assertOk()
        ->assertSee('Refundacje NFZ')
        ->assertSee('Jak zrealizować zlecenie')
        ->assertSee('+48 697 595 045')
        ->assertDontSee('Formularze do pobrania');
});
