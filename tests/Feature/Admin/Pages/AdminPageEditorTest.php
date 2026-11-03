<?php

use App\Enums\EditablePage;
use App\Models\Page;
use App\Models\User;
use App\Support\Pages\PageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pageEditorAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('lists every editable page for admins', function (): void {
    $response = $this->actingAs(pageEditorAdmin())
        ->get(route('admin.pages.index'))
        ->assertOk()
        ->assertSee('Edytor stron');

    foreach (EditablePage::cases() as $page) {
        $response
            ->assertSee($page->defaultTitle())
            ->assertSee(route('admin.pages.edit', $page), false);
    }
});

it('keeps the page editor away from customers and guests', function (): void {
    $this->get(route('admin.pages.index'))->assertRedirect(route('login'));

    $customer = User::factory()->create(['is_admin' => false]);

    $this->actingAs($customer)->get(route('admin.pages.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('admin.pages.edit', EditablePage::CONTACT))->assertForbidden();
    $this->actingAs($customer)
        ->patch(route('admin.pages.update', EditablePage::CONTACT), ['title' => 'Hacked'])
        ->assertForbidden();

    expect(Page::query()->count())->toBe(0);
});

it('prefills the editor with the current default texts', function (EditablePage $page, string $expectedText): void {
    $this->actingAs(pageEditorAdmin())
        ->get(route('admin.pages.edit', $page))
        ->assertOk()
        ->assertSee('trix-editor', false)
        ->assertSee($page->defaultTitle())
        ->assertSee($expectedText);
})->with([
    'rental' => [EditablePage::RENTAL, 'materace przeciwodleżynowe'],
    'delivery' => [EditablePage::DELIVERY_AND_PAYMENTS, 'Koszt i czas dostawy'],
    'returns' => [EditablePage::RETURNS_AND_WITHDRAWAL, 'Kto może odstąpić od umowy'],
    'complaints' => [EditablePage::COMPLAINTS_AND_WARRANTY, 'Za co odpowiadamy'],
    'contact' => [EditablePage::CONTACT, 'Dane sprzedawcy'],
    'nfz' => [EditablePage::NFZ, 'Na czym polega refundacja'],
]);

it('shows the edited texts on the public page', function (EditablePage $page): void {
    $this->actingAs(pageEditorAdmin())
        ->from(route('admin.pages.edit', $page))
        ->patch(route('admin.pages.update', $page), [
            'title' => 'Nowy tytuł strony',
            'intro' => 'Nowy <strong>wstęp</strong>',
            'content' => '<h2>Nowa sekcja</h2><p>Nowa treść z edytora.</p>',
            'seo_title' => 'Nowy tytuł SEO',
            'seo_description' => 'Nowy opis SEO',
        ])
        ->assertRedirect(route('admin.pages.edit', $page))
        ->assertSessionHas('success');

    $this->get(route($page->routeName()))
        ->assertOk()
        ->assertSee('Nowy tytuł strony')
        ->assertSee('Nowy <strong>wstęp</strong>', false)
        ->assertSee('<h2>Nowa sekcja</h2><p>Nowa treść z edytora.</p>', false)
        ->assertSee('<title>Nowy tytuł SEO', false)
        ->assertSee('Nowy opis SEO');
})->with(EditablePage::cases());

it('keeps following the template for fields saved unchanged or empty', function (): void {
    $defaults = PageContent::defaults(EditablePage::CONTACT);

    $this->actingAs(pageEditorAdmin())
        ->patch(route('admin.pages.update', EditablePage::CONTACT), [
            'title' => 'Skontaktuj się z nami',
            'intro' => str_replace("\n", "\r\n", $defaults->intro),
            'content' => '   ',
            'seo_title' => '',
            'seo_description' => '',
        ])
        ->assertRedirect();

    $stored = Page::query()->where('slug', EditablePage::CONTACT->value)->sole();

    expect($stored->title)->toBe('Skontaktuj się z nami')
        ->and($stored->intro)->toBeNull()
        ->and($stored->content)->toBeNull();

    config(['legal.seller.email' => 'nowy-adres@example.com']);

    $this->get(route('legal.contact'))
        ->assertOk()
        ->assertSee('Skontaktuj się z nami')
        ->assertSee('nowy-adres@example.com');
});

it('restores the default texts', function (): void {
    Page::query()->create([
        'slug' => EditablePage::NFZ->value,
        'title' => 'Zmieniony tytuł',
        'content' => '<p>Zmieniona treść</p>',
    ]);

    $this->actingAs(pageEditorAdmin())
        ->delete(route('admin.pages.reset', EditablePage::NFZ))
        ->assertRedirect(route('admin.pages.edit', EditablePage::NFZ));

    expect(Page::query()->count())->toBe(0);

    $this->get(route('legal.nfz'))
        ->assertOk()
        ->assertSee('Refundacje NFZ')
        ->assertSee('Na czym polega refundacja')
        ->assertDontSee('Zmieniona treść');
});

it('rejects an over-long title', function (): void {
    $this->actingAs(pageEditorAdmin())
        ->patch(route('admin.pages.update', EditablePage::CONTACT), [
            'title' => str_repeat('a', 256),
        ])
        ->assertSessionHasErrors('title');

    expect(Page::query()->count())->toBe(0);
});
