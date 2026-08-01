<?php

declare(strict_types=1);

use App\Services\Vermeiren\VermeirenCategoryUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers the complete Vermeiren Produkty menu hierarchy', function (): void {
    Http::fake([
        VermeirenCategoryUrlScraper::DEFAULT_URL => Http::response(vermeirenProductMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('vermeiren')
        ->and($result['top_categories'])->toHaveCount(12)
        ->and($result['categories'])->toHaveCount(48)
        ->and($result['category_urls'])->toHaveCount(48)
        ->and($result['product_category_urls'])->toHaveCount(39)
        ->and($result['visited_urls'])->toBe([
            VermeirenCategoryUrlScraper::DEFAULT_URL,
        ])
        ->and($result['failed_urls'])->toBe([]);

    expect(array_column($result['top_categories'], 'name'))->toBe([
        'Napęd elektryczny do wózków manualnych',
        'Wózki manualne',
        'Wózki elektryczne',
        'Skutery',
        'Świat dziecka',
        'Urządzenia do pionizacji',
        'Łóżka',
        'Podpórki/Pomoce lokomocyjne',
        'Sprzęt pomocniczy',
        'Sprzęt przeciwodleżynowy',
        'Rowery trójkołowe',
        'Stabilizacja',
    ]);

    expect($result['categories'][0])->toMatchArray([
        'external_category_id' => 'product-group:Napęd elektryczny do wózków manualnych',
        'name' => 'Napęd elektryczny do wózków manualnych',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Napęd elektryczny do wózków manualnych'],
        'has_children' => true,
        'is_product_category' => false,
        'page_type' => 'mainproduct_categories',
    ]);

    expect($result['categories'][1])->toMatchArray([
        'name' => 'KLAXON',
        'product_group' => 'Napęd elektryczny do wózków manualnych',
        'sub_group' => 'KLAXON',
        'level' => 2,
        'path' => ['Napęd elektryczny do wózków manualnych', 'KLAXON'],
        'is_product_category' => true,
    ]);

    expect($result['categories'][2])->toMatchArray([
        'name' => 'Blumil',
        'sub_group' => 'Blumil',
        'level' => 2,
        'is_product_category' => true,
    ]);

    $skutery = collect($result['categories'])->firstWhere('name', 'Skutery');

    expect($skutery)->toMatchArray([
        'level' => 1,
        'has_children' => false,
        'is_product_category' => true,
        'page_type' => 'mainproduct',
    ]);

    $neoflex = collect($result['categories'])->firstWhere('name', 'Neoflex');

    expect($neoflex)->toMatchArray([
        'top_category_name' => 'Stabilizacja',
        'page_type' => 'mainproduct_sub',
        'path' => ['Stabilizacja', 'Neoflex'],
    ]);
});

it('normalizes Vermeiren hosts and category query strings', function (): void {
    Http::fake([
        VermeirenCategoryUrlScraper::DEFAULT_URL => Http::response(vermeirenProductMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([
            'http://vermeiren.pl/web/web.nsf/home.xsp?CountryPLPLProductGroup',
        ]);

    expect($result['start_urls'])->toBe([
        VermeirenCategoryUrlScraper::DEFAULT_URL,
    ])->and($result['product_category_urls'])->toContain(
        'https://www.vermeiren.pl/web/web.nsf/mainproduct.xsp?CountryPLPLProductGroupW%C3%B3zki%20manualneSubGroupSpecjalne'
    );
});

it('retries transient Vermeiren category page failures', function (): void {
    Http::fake([
        VermeirenCategoryUrlScraper::DEFAULT_URL => Http::sequence()
            ->push('', 500)
            ->push(vermeirenProductMenuFixture(), 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenCategoryUrlScraper::class)
        ->withAttempts(2)
        ->withRetryDelayMilliseconds(0)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['top_categories'])->toHaveCount(12)
        ->and($result['failed_urls'])->toBe([]);

    Http::assertSentCount(2);
});

it('records failed Vermeiren category discovery URLs', function (): void {
    Http::fake([
        VermeirenCategoryUrlScraper::DEFAULT_URL => Http::response('', 404),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenCategoryUrlScraper::class)
        ->withAttempts(1)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['failed_urls'])->toBe([
            VermeirenCategoryUrlScraper::DEFAULT_URL => 'HTTP 404',
        ]);
});

it('runs the Vermeiren category command and saves its JSON result', function (): void {
    Http::fake([
        VermeirenCategoryUrlScraper::DEFAULT_URL => Http::response(vermeirenProductMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $relativePath = 'scrapers/vermeiren/categories-test.json';
    $absolutePath = storage_path('app/'.$relativePath);

    @unlink($absolutePath);

    $exitCode = Artisan::call('vermeiren:categories', [
        '--save' => $relativePath,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
        '--insecure' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('TLS certificate verification is disabled for this Vermeiren run.')
        ->and($output)->toContain('Top product categories: 12')
        ->and($output)->toContain('Discovered category URLs: 48')
        ->and($output)->toContain('Product-scraping category URLs: 39')
        ->and($output)->toContain('Saved discovery result to storage/app/'.$relativePath)
        ->and(is_file($absolutePath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($absolutePath), true, 512, JSON_THROW_ON_ERROR);

    expect($saved['source'])->toBe('vermeiren')
        ->and($saved['top_categories'])->toHaveCount(12)
        ->and($saved['product_category_urls'])->toHaveCount(39);

    @unlink($absolutePath);
});

function vermeirenProductMenuFixture(): string
{
    return <<<'HTML'
        <!DOCTYPE html>
        <html lang="pl"><body>
            <nav><ul class="nav navbar-nav">
                <li><a href="home.xsp?CountryPLPLProductGroup">Strona Główna</a></li>
                <li class="dropdown">
                    <a class="dropdown-toggle" href="#">Produkty<b class="caret"></b></a>
                    <ul class="dropdown-menu">
                        <li class="dropdown-submenu"><a href="#">Napęd elektryczny do wózków manualnych</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupNapęd elektryczny do wózków manualnychSubGroup">Napęd elektryczny do wózków manualnych</a></li>
                            <div><li><a href="mainproduct.xsp?CountryPLPLProductGroupNapęd elektryczny do wózków manualnychSubGroupKLAXON"><img alt="Klaxon"></a></li></div>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupNapęd elektryczny do wózków manualnychSubGroupBlumil"><img alt="Klaxon"></a></li>
                        </ul></li>
                        <li class="dropdown-submenu"><a href="#">Wózki manualne</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupWózki manualneSubGroup">Wózki manualne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupWózki inwalidzkie standardowe">Wózki inwalidzkie standardowe</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupWózki inwalidzkie ze stopów lekkich">Wózki inwalidzkie ze stopów lekkich</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupDo samodzielnego poruszania aktywne">Do samodzielnego poruszania aktywne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupSpecjalne">Specjalne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupSpecjalne hemiplegia">Specjalne hemiplegia</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupDla dzieci">Dla dzieci</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupAsystent opiekuna">Asystent opiekuna</a></li>
                        </ul></li>
                        <li class="dropdown-submenu"><a href="#">Wózki elektryczne</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupWózki elektryczneSubGroup">Wózki elektryczne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki elektryczneSubGroupPokojowe">Pokojowe</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki elektryczneSubGroupTerenowo-pokojowe">Terenowo-pokojowe</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupWózki elektryczneSubGroupDziecięce">Dziecięce</a></li>
                        </ul></li>
                        <li><a href="mainproduct.xsp?CountryPLPLProductGroupSkuterySubGroup">Skutery</a></li>
                        <li class="dropdown-submenu"><a href="#">Świat dziecka</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupŚwiat dzieckaSubGroup">Świat dziecka</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupŚwiat dzieckaSubGroupFoteliki">Foteliki</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupŚwiat dzieckaSubGroupPodpórki">Podpórki</a></li>
                        </ul></li>
                        <li><a href="mainproduct.xsp?CountryPLPLProductGroupUrządzenia do pionizacjiSubGroup">Urządzenia do pionizacji</a></li>
                        <li><a href="mainproduct.xsp?CountryPLPLProductGroupŁóżkaSubGroup">Łóżka</a></li>
                        <li class="dropdown-submenu"><a href="#">Podpórki/Pomoce lokomocyjne</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupPodpórki/Pomoce lokomocyjneSubGroup">Podpórki/Pomoce lokomocyjne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupPodpórki/Pomoce lokomocyjneSubGroupRolatory">Rolatory</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupPodpórki/Pomoce lokomocyjneSubGroupPodpórki">Podpórki</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupPodpórki/Pomoce lokomocyjneSubGroupKule">Kule</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupPodpórki/Pomoce lokomocyjneSubGroupLaski">Laski</a></li>
                        </ul></li>
                        <li class="dropdown-submenu"><a href="#">Sprzęt pomocniczy</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupSprzęt pomocniczySubGroup">Sprzęt pomocniczy</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt pomocniczySubGroupFotele geriatryczne">Fotele geriatryczne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt pomocniczySubGroupWózki toaletowe">Wózki toaletowe</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt pomocniczySubGroupSprzęt pielęgnacyjno-toaletowy">Sprzęt pielęgnacyjno-toaletowy</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt pomocniczySubGroupPodnośniki kąpielowo-transportowe">Podnośniki kąpielowo-transportowe</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt pomocniczySubGroupRotory">Rotory</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt pomocniczySubGroupRampy">Rampy</a></li>
                        </ul></li>
                        <li class="dropdown-submenu"><a href="#">Sprzęt przeciwodleżynowy</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupSprzęt przeciwodleżynowySubGroup">Sprzęt przeciwodleżynowy</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt przeciwodleżynowySubGroupPoduszki przeciwodleżynowe">Poduszki przeciwodleżynowe</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt przeciwodleżynowySubGroupMaterace przeciwodlweżynowe pasywne">Materace przeciwodlweżynowe pasywne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt przeciwodleżynowySubGroupMaterace przeciwodlweżynowe aktywne">Materace przeciwodlweżynowe aktywne</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupSprzęt przeciwodleżynowySubGroupOchraniacze przeciwodleżynowe">Ochraniacze przeciwodleżynowe</a></li>
                        </ul></li>
                        <li class="dropdown-submenu"><a href="#">Rowery trójkołowe</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupRowery trójkołoweSubGroup">Rowery trójkołowe</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupRowery trójkołoweSubGroupDziecięce dla dziewczynek">Dziecięce dla dziewczynek</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupRowery trójkołoweSubGroupDziecięce dla chłopców">Dziecięce dla chłopców</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupRowery trójkołoweSubGroupDla młodzieży">Dla młodzieży</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupRowery trójkołoweSubGroupDla dorosłych">Dla dorosłych</a></li>
                        </ul></li>
                        <li class="dropdown-submenu"><a href="#">Stabilizacja</a><ul class="dropdown-menu sub-menu">
                            <li><a href="mainproduct_categories.xsp?CountryPLPLProductGroupStabilizacjaSubGroup">Stabilizacja</a></li>
                            <li><a href="mainproduct_sub.xsp?CountryPLPLProductGroupStabilizacjaSubGroupNeoflex">Neoflex</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupStabilizacjaSubGroupPhysipro">Physipro</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupStabilizacjaSubGroupVicair">Vicair</a></li>
                            <li><a href="mainproduct.xsp?CountryPLPLProductGroupStabilizacjaSubGroupStabilo">Stabilo</a></li>
                        </ul></li>
                    </ul>
                </li>
                <li><a href="catalogue.xsp?CountryPLPLProductGroup">Katalogi</a></li>
            </ul></nav>
        </body></html>
        HTML;
}
