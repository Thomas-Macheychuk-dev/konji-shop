<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('explains how to fill an NFZ order and lists both forms', function (): void {
    $this
        ->get(route('legal.nfz'))
        ->assertOk()
        ->assertSee('Refundacje NFZ')
        ->assertSee('Jak zrealizować zlecenie')
        ->assertSee('Zlecenie na zaopatrzenie w wyroby medyczne')
        ->assertSee('Upoważnienie do odbioru')
        ->assertSee('+48 697 595 045');
});

it('links each NFZ form only when its PDF is published', function (): void {
    config()->set('legal.documents.nfz_order_form_pdf', 'legal/nfz-test/zlecenie.pdf');
    config()->set('legal.documents.nfz_collection_authorisation_pdf', 'legal/nfz-test/upowaznienie.pdf');

    $directory = public_path('legal/nfz-test');
    File::ensureDirectoryExists($directory);
    File::put($directory.'/zlecenie.pdf', '%PDF-1.4 test');

    try {
        $this
            ->get(route('legal.nfz'))
            ->assertOk()
            ->assertSee(asset('legal/nfz-test/zlecenie.pdf'), false)
            ->assertDontSee(asset('legal/nfz-test/upowaznienie.pdf'), false)
            ->assertSee('Dostępny w sklepie lub na prośbę e-mailem');
    } finally {
        File::deleteDirectory($directory);
    }
});
