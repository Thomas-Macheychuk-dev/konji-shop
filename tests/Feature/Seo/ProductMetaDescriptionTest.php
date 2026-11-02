<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Seo\ProductMetaDescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('recognises weak meta descriptions', function (?string $meta, bool $weak): void {
    expect(ProductMetaDescription::isWeak($meta))->toBe($weak);
})->with([
    'empty' => [null, true],
    'short' => ['Orteza stopy ze stabilizatorem stawu skokowego.', true],
    'supplier breadcrumb' => ['Vermeiren Sprzęt przeciwodleżynowy Poduszki przeciwodleżynowe VICAIR ACTIVE O2', true],
    'supplier shop' => ['Sklep RelaxSan oferuje Pończochy przeciwżylakowe do paska 280 DEN 2 klasa ucisku 22-27 mmHg.', true],
    'shop medi' => ['Odkryj pończochy Mediven Forte w Shop Medi. Trwałe, wygodne, wykonane z dzianiny o wysokiej gęstości.', true],
    'boilerplate' => ['bluza kosmetyczna damska ze spandexem MIA. Sprawdź opis i parametry produktu w Ortezka.pl.', true],
    'good' => ['Poduszka przeciwodleżynowa z komorami powietrznymi. Pokrowiec zdejmowany, do prania w 60°C, dostępne cztery rozmiary.', false],
    'scraped page tabs' => ['Orteza stawu nadgarstkowego SZW-01 Opis Wskazania Dostępne rozmiary Rozmiar UNI Detale produktu Powiązane produkty', true],
    'sizes mentioned in real copy' => ['Różne modele dętek do wózków inwalidzkich – dostępne rozmiary i typy wentyli: prosty, 90/30, 90/90, 45/45 – do wózków aktywnych i standardowych', false],
    'size prompt in marketing copy' => ['Wybierz ortezę kciuka AT53065! Komfortowa tkanina welurowa, aluminiowa szyna i doskonałe wsparcie w leczeniu urazów. Sprawdź dostępne rozmiary.', false],
    'broken list punctuation' => ['Przeciążenia mięśnia brzuchatego łydki,. Niewydolność krążenia kończyn dolnych,. Zapalenie rozcięgna.', true],
]);

it('repairs broken list punctuation in stored descriptions and keeps valid long ones whole', function (): void {
    expect(ProductMetaDescription::clean('Przeciążenia mięśnia łydki,. Niewydolność krążenia,. Zapalenie rozcięgna podeszwowego,.'))
        ->toBe('Przeciążenia mięśnia łydki. Niewydolność krążenia. Zapalenie rozcięgna podeszwowego.');

    $long = 'POKROWIEC ELASTYCZNY – miękki i przyjemny w dotyku pokrowiec wykonany z „oddychającej” dzianiny elastycznej. '
        .'Doskonale dopasowuje się poduszki, a tym samym, idealnie odwzorowuje jej kształt. '
        .'Materiał odznacza się niebywałą delikatnością oraz funkcją sprawnego odprowadzania wilgoci.';

    expect(ProductMetaDescription::clean('<p>'.$long.'</p>'))->toBe($long)
        ->and(ProductMetaDescription::isWeak($long))->toBeFalse();
});

it('drops trailing list commas and list lead-ins when building from the description', function (): void {
    $html = '<p>Dzięki bazie peruki Rich Mono zyskujesz:</p><ul><li>naturalny prześwit skóry na górze głowy,</li><li>lekkość i przewiewność przez cały dzień noszenia,</li></ul>';

    expect(ProductMetaDescription::build($html, null, 'Rich Mono'))
        ->toBe('Naturalny prześwit skóry na górze głowy. Lekkość i przewiewność przez cały dzień noszenia.');
});

it('never rebuilds a description from scraped page tabs or shop wording', function (): void {
    $scraped = '<p>Kolano Orteza stawu kolanowego DR-K092 Orteza stawu kolanowego DR-K092 Opis Wskazania Dostępne rozmiary Rozmiar M L XL cm 26 – 31 31 – 36 36 – 41 Detale produktu Powiązane produkty Masz jakieś pytania? Jeśli potrzebujesz pomocy lub dodatkowych informacji skontaktuj się z nami mailowo lub telefonicznie</p>';

    expect(ProductMetaDescription::build($scraped, null, 'Orteza stawu kolanowego DR-K092'))->toBeNull();

    $shop = '<p>W naszym sklepie internetowym znajdziesz pełną gamę dętek do wózków inwalidzkich, zaprojektowanych z myślą o niezawodności.</p>'
        .'<p>Dętki są dostępne w rozmiarach od 12 do 24 cali, z wentylami prostymi i kątowymi do wózków aktywnych.</p>';

    expect(ProductMetaDescription::build($shop, null, 'Dętki'))
        ->toBe('Dętki są dostępne w rozmiarach od 12 do 24 cali, z wentylami prostymi i kątowymi do wózków aktywnych.');
});

it('builds whole sentences from the product description without headings or tables', function (): void {
    $html = '<h2>VICAIR ACTIVE O2</h2><p>Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.</p>'
        .'<table><tr><td>Waga</td><td>1,2 kg</td></tr></table><p>Dostępna w czterech rozmiarach dopasowanych do szerokości siedziska wózka.</p>';

    $meta = ProductMetaDescription::build($html, null, 'VICAIR ACTIVE O2');

    expect($meta)->toBe('Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.')
        ->and(mb_strlen($meta))->toBeLessThanOrEqual(ProductMetaDescription::MAX_LENGTH)
        ->and($meta)->not->toContain('Waga');
});

it('removes supplier shop wording and cuts a single long sentence at a word boundary', function (): void {
    $long = '<p>Sklep RelaxSan oferuje pończochy przeciwżylakowe do paska wykonane z miękkiej przędzy z mikrofibry, która zapewnia stopniowany ucisk od kostki aż do uda przez cały dzień noszenia bez uczucia ściskania.</p>';
    $meta = ProductMetaDescription::build($long, null, 'Pończochy');

    expect($meta)->toStartWith('Pończochy przeciwżylakowe do paska')
        ->and($meta)->toEndWith('…')
        ->and(mb_strlen($meta))->toBeLessThanOrEqual(ProductMetaDescription::MAX_LENGTH)
        ->and($meta)->not->toContain('Sklep');
});

it('never uses manufacturer or contact details as the meta description', function (): void {
    $html = '<p>Materac HF6001 bez pompy.</p><p>Producent: Reha Fund sp. z o. o., ul. Staniewicka 14, 03-310 Warszawa, www.rehafund.pl, e-mail: info@rehafund.pl.</p>';

    expect(ProductMetaDescription::build($html, null, 'Materac HF6001'))->toBeNull();

    $html .= '<p>Materac przeciwodleżynowy o konstrukcji bąbelkowej wykonany z PVC, przeznaczony do stosowania z pompą zmiennociśnieniową.</p>';

    expect(ProductMetaDescription::build($html, null, 'Materac HF6001'))
        ->toBe('Materac HF6001 bez pompy. Materac przeciwodleżynowy o konstrukcji bąbelkowej wykonany z PVC, przeznaczony do stosowania z pompą zmiennociśnieniową.');
});

it('never cuts a word in half and capitalises lowercase list items', function (): void {
    $wig = '<p>Model Ginger to peruka z włosów syntetycznych utkanych na bazie, którą charakteryzuje wykonanie maszynowe na tresach, mikroskóra na czubku głowy oraz linia przedziałka.</p>';
    $meta = ProductMetaDescription::build($wig, null, 'Ginger');

    expect($meta)->toEndWith('głowy oraz…')
        ->and(mb_strlen($meta))->toBeLessThanOrEqual(ProductMetaDescription::MAX_LENGTH);

    $list = '<ul><li>model dostępny w kilku wersjach artykulatora</li><li>miękkie, naturalne dziąsła i realistyczne zęby</li><li>kompatybilny z wszystkimi fantomami NISSIN</li></ul>';

    expect(ProductMetaDescription::build($list, null, 'Model PDI2001'))
        ->toBe('Model dostępny w kilku wersjach artykulatora. Miękkie, naturalne dziąsła i realistyczne zęby. Kompatybilny z wszystkimi fantomami NISSIN.');
});

it('returns nothing when the description is too thin to describe the product', function (): void {
    expect(ProductMetaDescription::build('<p>Orteza kolana.</p>', 'Orteza.', 'Orteza kolana'))->toBeNull();
});

it('previews, writes with a backup and rolls back weak product meta descriptions', function (): void {
    Storage::fake('local');
    $weak = Product::query()->forceCreate([
        'name' => 'VICAIR ACTIVE O2', 'slug' => 'vicair-active-o2', 'status' => ProductStatus::ACTIVE,
        'seo_description' => 'Vermeiren Sprzęt przeciwodleżynowy Poduszki przeciwodleżynowe VICAIR ACTIVE O2',
        'description' => '<p>Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.</p>',
    ]);
    $good = Product::query()->forceCreate([
        'name' => 'Good', 'slug' => 'good', 'status' => ProductStatus::ACTIVE,
        'seo_description' => 'Poduszka przeciwodleżynowa z komorami powietrznymi. Pokrowiec zdejmowany, do prania w 60°C, dostępne cztery rozmiary.',
        'description' => '<p>Inny opis produktu, który nie powinien zostać użyty, bo meta jest już dobra.</p>',
    ]);
    $thin = Product::query()->forceCreate([
        'name' => 'Thin', 'slug' => 'thin', 'status' => ProductStatus::ACTIVE, 'seo_description' => null,
        'description' => '<p>Krótki opis.</p>', 'external_source' => 'eldan',
    ]);
    Product::query()->forceCreate([
        'name' => 'Archived', 'slug' => 'archived', 'status' => ProductStatus::ARCHIVED, 'seo_description' => null,
        'description' => '<p>Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.</p>',
    ]);

    expect(Artisan::call('seo:product-meta-descriptions'))->toBe(0)
        ->and(Artisan::output())->toContain('WEAK_META=2')->toContain('REBUILT_META=1')->toContain('NO_SOURCE eldan=1')->toContain('MODE=READ_ONLY');

    expect(Artisan::call('seo:product-meta-descriptions', ['--write' => true]))->toBe(0);
    $output = Artisan::output();
    $backup = trim(str($output)->after('BACKUP=')->before("\n"));
    expect($output)->toContain('WRITTEN_META=1')
        ->and($weak->fresh()->seo_description)->toBe('Poduszka z komorami powietrznymi dopasowuje się do kształtu ciała. Pokrowiec jest zdejmowany i można go prać.')
        ->and($good->fresh()->seo_description)->toStartWith('Poduszka przeciwodleżynowa')
        ->and($thin->fresh()->seo_description)->toBeNull();

    expect(Artisan::call('seo:product-meta-descriptions', ['--rollback' => $backup]))->toBe(0)
        ->and(Artisan::output())->toContain('RESTORED_META=1 OF 1')
        ->and($weak->fresh()->seo_description)->toBe('Vermeiren Sprzęt przeciwodleżynowy Poduszki przeciwodleżynowe VICAIR ACTIVE O2');
});

it('skips advertiser disclosures, manual links and file names and drops list bullets', function (): void {
    $html = '<p>Klipsy mocujące do wszystkich kul FDI:</p><p>• wyposażone w system bezpieczeństwa, • dopasowane do wszystkich modeli kul FDI,</p>'
        .'<p>Podmiot prowadzący reklamę: Antar Spółka Jawna.</p><p>User manual (multiversion).</p><p>CE_Vicky_2024-07-23_pl.pdf</p>';

    expect(ProductMetaDescription::build($html, null, 'Klipsy FDI'))
        ->toBe('Wyposażone w system bezpieczeństwa. Dopasowane do wszystkich modeli kul FDI.');
});

it('ignores the manuals and medical notice the vermeiren importer appends', function (): void {
    $html = '<p>Ułatwia osobom starszym wchodzenie do wanny i wychodzenie z niej bez pomocy drugiej osoby.</p>'
        .'<section class="vermeiren-documents"><h2>Dokumenty i materiały</h2><h3>Instrukcje i materiały wideo</h3><ul>'
        .'<li><a href="https://d1epzvmd6naoje.cloudfront.net/products/vermeiren/x/documents/manual/user-manual.pdf">User manual (multi version)</a></li>'
        .'<li><a href="https://d1epzvmd6naoje.cloudfront.net/products/vermeiren/x/documents/ce/ce-vicky.pdf">Deklaracja zgodności CE dla modelu Vicky</a></li></ul></section>'
        .'<section class="vermeiren-medical-notice"><p><strong>To jest wyrób medyczny. Używaj go zgodnie z instrukcją używania lub etykietą.</strong></p></section>';

    expect(ProductMetaDescription::build($html, null, 'Vicky'))
        ->toBe('Ułatwia osobom starszym wchodzenie do wanny i wychodzenie z niej bez pomocy drugiej osoby.');
});
