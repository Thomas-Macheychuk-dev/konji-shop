<?php

use App\Enums\CategoryStatus;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('renders homepage SEO metadata, structured data and category links', function (): void {
    Config::set('app.name', 'Konji Shop');
    Config::set('app.url', 'https://konji-shop.example.test');
    Config::set('legal.seller.shop_name', 'Konji Shop');
    Config::set('legal.seller.company_name', 'Konji Shop Sp. z o.o.');

    $category = Category::query()->create([
        'name' => 'Knee supports',
        'slug' => 'knee-supports',
        'description' => 'Support products for knees.',
        'status' => CategoryStatus::ACTIVE,
    ]);

    Category::query()->create([
        'name' => 'Archived supports',
        'slug' => 'archived-supports',
        'description' => 'Archived category.',
        'status' => CategoryStatus::ARCHIVED,
    ]);

    $homeUrl = route('home');

    $this
        ->get($homeUrl)
        ->assertOk()
        ->assertSee('<title>Odzież medyczna, stabilizatory ortopedyczne i produkty do regeneracji - Konji Shop</title>', false)
        ->assertSee('<meta name="description" content="Kupuj odzież medyczną, stabilizatory, ortezy i produkty do regeneracji z dostawą na terenie Polski.">', false)
        ->assertSee('<link rel="canonical" href="'.$homeUrl.'">', false)
        ->assertSee('"@type": "WebSite"', false)
        ->assertSee('"@type": "Organization"', false)
        ->assertSee(route('categories.show', $category->slug), false)
        ->assertDontSee('Archived supports');
});
