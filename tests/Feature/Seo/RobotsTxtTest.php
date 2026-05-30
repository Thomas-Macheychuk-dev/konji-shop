<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('renders robots txt with sitemap location', function (): void {
    Config::set('app.url', 'https://konji-shop.example.test');

    $response = $this
        ->get(route('robots'))
        ->assertOk()
        ->assertSee('User-agent: *')
        ->assertSee('Allow: /')
        ->assertSee('Disallow: /admin')
        ->assertSee('Disallow: /checkout')
        ->assertSee('Disallow: /cart')
        ->assertSee('Disallow: /account')
        ->assertSee('Sitemap: '.route('sitemap'));

    expect($response->headers->get('Content-Type'))->toContain('text/plain');
});
