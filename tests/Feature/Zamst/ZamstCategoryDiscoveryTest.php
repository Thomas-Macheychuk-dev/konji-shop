<?php

declare(strict_types=1);

use App\Services\Zamst\ZamstCategoryUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers Zamst WooCommerce category hierarchy and catalogue sections', function (): void {
    Http::fake([
        ZamstCategoryUrlScraper::DEFAULT_URL => Http::response(<<<'HTML'
            <!doctype html>
            <html lang="pl-PL"><body>
                <nav>
                    <a href="/kategoria-produktu/stabilizator-kolana-zamst/">Stabilizatory stawu kolanowego</a>
                    <a href="/kategoria-produktu/stabilizator-kolana-zamst/stabilizator-na-rzepke/">Stabilizator na rzepkę</a>
                    <a href="https://example.com/kategoria-produktu/external/">External</a>
                </nav>
                <ul class="category-list">
                    <li>
                        <h2><a href="https://zamst.com.pl/kategoria-produktu/stabilizator-kolana-zamst/">Stabilizatory stawu kolanowego</a></h2>
                        <a class="uk-card" href="/produkt/stabilizator-kolana-jk-2/"><h3><span>JK-2</span></h3></a>
                        <a class="uk-card" href="/produkt/stabilizator-kolana-zk-x/"><h3><span>ZK-X</span></h3></a>
                    </li>
                </ul>
            </body></html>
            HTML),
    ]);

    $result = app(ZamstCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('zamst')
        ->and($result['failed_urls'])->toBe([])
        ->and($result['category_urls'])->toContain(
            'https://zamst.com.pl/kategoria-produktu/stabilizator-kolana-zamst/',
            'https://zamst.com.pl/kategoria-produktu/stabilizator-kolana-zamst/stabilizator-na-rzepke/',
        )
        ->and($result['categories'])->toHaveCount(2);

    $parent = collect($result['categories'])
        ->firstWhere('external_category_id', 'stabilizator-kolana-zamst');
    $child = collect($result['categories'])
        ->firstWhere('external_category_id', 'stabilizator-kolana-zamst/stabilizator-na-rzepke');

    expect($parent)->toMatchArray([
        'name' => 'Stabilizatory stawu kolanowego',
        'level' => 1,
        'is_catalogue_section' => true,
        'product_count' => 2,
        'has_children' => true,
    ])->and($child)->toMatchArray([
        'name' => 'Stabilizator na rzepkę',
        'level' => 2,
        'parent_external_category_id' => 'stabilizator-kolana-zamst',
        'path' => ['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'],
    ]);
});

it('saves Zamst category discovery JSON from the command', function (): void {
    $relativePath = 'scrapers/zamst/categories-test.json';
    $absolutePath = storage_path('app/'.$relativePath);
    @unlink($absolutePath);

    Http::fake([
        ZamstCategoryUrlScraper::DEFAULT_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="/kategoria-produktu/stabilizator-barkowy/">Stabilizator barkowy</a>
            </body></html>
            HTML),
    ]);

    $exit = Artisan::call('zamst:categories', [
        '--request-delay-ms' => '0',
        '--no-progress' => true,
        '--save' => $relativePath,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Discovered category URLs: 1')
        ->and(is_file($absolutePath))->toBeTrue();

    @unlink($absolutePath);
});
