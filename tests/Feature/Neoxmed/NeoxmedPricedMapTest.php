<?php

declare(strict_types=1);

use App\Services\Neoxmed\NeoxmedImportMapper;
use App\Services\Neoxmed\NeoxmedPricedMapBuilder;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

it('builds a commercial approval template directly from the frozen import map', function (): void {
    $map = neoxmedPricedTestImportMap(neoxmedPricedTestProduct());
    $sha = hash('sha256', json_encode($map, JSON_THROW_ON_ERROR));

    $template = app(NeoxmedPricedMapBuilder::class)->buildApprovalTemplate($map, $sha);

    expect($template['database_writes'])->toBeFalse()
        ->and($template['source'])->toBe('neoxmed')
        ->and($template['currency'])->toBe('PLN')
        ->and($template['import_map_sha256'])->toBe($sha)
        ->and($template['products'])->toHaveCount(1)
        ->and($template['products'][0])->toMatchArray([
            'external_product_id' => 'B-01',
            'planned_sku' => 'NEOX-B-01',
            'name' => 'Kamizelka stawu barkowego',
            'net_amount_pln' => null,
            'gross_amount_pln' => null,
            'vat_rate' => null,
        ]);
});

it('keeps incomplete commercial approvals non writable without inventing price or VAT', function (): void {
    $map = neoxmedPricedTestImportMap(neoxmedPricedTestProduct());
    $raw = json_encode($map, JSON_THROW_ON_ERROR);
    $sha = hash('sha256', $raw);
    $template = app(NeoxmedPricedMapBuilder::class)->buildApprovalTemplate($map, $sha);
    $approvalRaw = json_encode($template, JSON_THROW_ON_ERROR);

    $result = app(NeoxmedPricedMapBuilder::class)->build(
        $map,
        $sha,
        $template,
        hash('sha256', $approvalRaw),
    );

    expect($result['errors'])->toBe([])
        ->and($result['summary'])->toMatchArray([
            'approved_products' => 0,
            'products_without_price' => 1,
            'products_without_vat' => 1,
            'gross_vat_mismatches' => 0,
            'required_media_missing' => 0,
        ])
        ->and($result['blocking_review_items'])->not->toBe([])
        ->and($result['ready_for_database_write'])->toBeFalse()
        ->and($result['products'][0]['pricing']['gross_minor'])->toBeNull()
        ->and($result['products'][0]['variants'][0]['vat_rate'])->toBeNull();
});

it('applies explicit PLN pricing and VAT only when gross arithmetic matches exactly', function (): void {
    $map = neoxmedPricedTestImportMap(neoxmedPricedTestProduct());
    $raw = json_encode($map, JSON_THROW_ON_ERROR);
    $sha = hash('sha256', $raw);
    $approvals = app(NeoxmedPricedMapBuilder::class)->buildApprovalTemplate($map, $sha);
    $approvals['products'][0]['net_amount_pln'] = '100.00';
    $approvals['products'][0]['gross_amount_pln'] = '108.00';
    $approvals['products'][0]['vat_rate'] = 8;
    $approvalRaw = json_encode($approvals, JSON_THROW_ON_ERROR);

    $result = app(NeoxmedPricedMapBuilder::class)->build(
        $map,
        $sha,
        $approvals,
        hash('sha256', $approvalRaw),
    );

    expect($result['errors'])->toBe([])
        ->and($result['blocking_review_items'])->toBe([])
        ->and($result['summary'])->toMatchArray([
            'approved_products' => 1,
            'products_without_price' => 0,
            'products_without_vat' => 0,
            'gross_vat_mismatches' => 0,
            'required_media_missing' => 0,
            'vat_rate_counts' => [8 => 1],
        ])
        ->and($result['products'][0]['pricing'])->toMatchArray([
            'net_minor' => 10000,
            'gross_minor' => 10800,
            'vat_rate' => 8,
            'currency' => 'PLN',
            'requires_review' => false,
        ])
        ->and($result['products'][0]['variants'][0])->toMatchArray([
            'price_net_minor' => 10000,
            'price_gross_minor' => 10800,
            'vat_rate' => 8,
            'currency' => 'PLN',
        ])
        ->and($result['ready_for_database_write'])->toBeTrue();
});

it('rejects gross VAT mismatches instead of normalizing approved money silently', function (): void {
    $map = neoxmedPricedTestImportMap(neoxmedPricedTestProduct());
    $raw = json_encode($map, JSON_THROW_ON_ERROR);
    $sha = hash('sha256', $raw);
    $approvals = app(NeoxmedPricedMapBuilder::class)->buildApprovalTemplate($map, $sha);
    $approvals['products'][0]['net_amount_pln'] = '100.00';
    $approvals['products'][0]['gross_amount_pln'] = '123.00';
    $approvals['products'][0]['vat_rate'] = 8;
    $approvalRaw = json_encode($approvals, JSON_THROW_ON_ERROR);

    $result = app(NeoxmedPricedMapBuilder::class)->build(
        $map,
        $sha,
        $approvals,
        hash('sha256', $approvalRaw),
    );

    expect($result['summary']['gross_vat_mismatches'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('gross/VAT mismatch')
        ->and($result['ready_for_database_write'])->toBeFalse();
});

it('requires approved HTTPS media when the structural map has no normal source product image', function (): void {
    $source = neoxmedPricedTestProduct();
    $source['external_product_id'] = 'K-01';
    $source['sku'] = 'K-01';
    $source['source_code'] = 'K-01';
    $source['slug'] = 'k-01-stabilizator-stawu-kolanowego';
    $source['name'] = 'Stabilizator stawu kolanowego';
    $source['images'] = [];
    $map = neoxmedPricedTestImportMap($source);
    $raw = json_encode($map, JSON_THROW_ON_ERROR);
    $sha = hash('sha256', $raw);
    $approvals = app(NeoxmedPricedMapBuilder::class)->buildApprovalTemplate($map, $sha);
    $approvals['products'][0]['net_amount_pln'] = '200.00';
    $approvals['products'][0]['gross_amount_pln'] = '216.00';
    $approvals['products'][0]['vat_rate'] = 8;

    $withoutMedia = app(NeoxmedPricedMapBuilder::class)->build(
        $map,
        $sha,
        $approvals,
        hash('sha256', json_encode($approvals, JSON_THROW_ON_ERROR)),
    );

    expect($withoutMedia['summary']['required_media_missing'])->toBe(1)
        ->and($withoutMedia['ready_for_database_write'])->toBeFalse();

    $approvals['products'][0]['media_override_url'] = 'https://example.com/approved/k-01.jpg';
    $approvals['products'][0]['media_override_alt'] = 'Neox K-01 Stabilizator stawu kolanowego';
    $approvalRaw = json_encode($approvals, JSON_THROW_ON_ERROR);
    $withMedia = app(NeoxmedPricedMapBuilder::class)->build(
        $map,
        $sha,
        $approvals,
        hash('sha256', $approvalRaw),
    );

    expect($withMedia['errors'])->toBe([])
        ->and($withMedia['blocking_review_items'])->toBe([])
        ->and($withMedia['summary']['required_media_missing'])->toBe(0)
        ->and($withMedia['summary']['media_overrides'])->toBe(1)
        ->and($withMedia['products'][0]['images'])->toContain([
            'source_url' => 'https://example.com/approved/k-01.jpg',
            'alt' => 'Neox K-01 Stabilizator stawu kolanowego',
            'role' => 'product',
            'source' => 'commercial_approval_override',
        ])
        ->and($withMedia['ready_for_database_write'])->toBeTrue();
});

it('generates and consumes priced map files under storage app without database writes', function (): void {
    $importRelative = 'scrapers/neoxmed/import-map-priced-test.json';
    $approvalRelative = 'scrapers/neoxmed/commercial-approvals-priced-test.json';
    $pricedRelative = 'scrapers/neoxmed/priced-map-test.json';
    $importPath = storage_path('app/'.$importRelative);
    $approvalPath = storage_path('app/'.$approvalRelative);
    $pricedPath = storage_path('app/'.$pricedRelative);
    @unlink($importPath);
    @unlink($approvalPath);
    @unlink($pricedPath);
    if (! is_dir(dirname($importPath))) {
        mkdir(dirname($importPath), 0775, true);
    }

    $map = neoxmedPricedTestImportMap(neoxmedPricedTestProduct());
    file_put_contents($importPath, json_encode($map, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $templateOutput = new BufferedOutput;
    $templateExit = Artisan::call('neoxmed:priced-map', [
        '--from' => $importRelative,
        '--approvals' => $approvalRelative,
        '--write-template' => true,
    ], $templateOutput);
    $templateText = $templateOutput->fetch();

    expect($templateExit)->toBe(0)
        ->and($templateText)->toContain('Database writes: NO')
        ->and(is_file($approvalPath))->toBeTrue();

    $approvals = json_decode((string) file_get_contents($approvalPath), true, flags: JSON_THROW_ON_ERROR);
    $approvals['products'][0]['net_amount_pln'] = '100.00';
    $approvals['products'][0]['gross_amount_pln'] = '108.00';
    $approvals['products'][0]['vat_rate'] = 8;
    file_put_contents($approvalPath, json_encode($approvals, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $buildOutput = new BufferedOutput;
    $buildExit = Artisan::call('neoxmed:priced-map', [
        '--from' => $importRelative,
        '--approvals' => $approvalRelative,
        '--save' => $pricedRelative,
        '--show-review' => true,
    ], $buildOutput);
    $buildText = $buildOutput->fetch();

    expect($buildExit)->toBe(0)
        ->and($buildText)->toContain('Approved products: 1')
        ->and($buildText)->toContain('Ready for database write: YES')
        ->and(is_file($pricedPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($pricedPath), true, flags: JSON_THROW_ON_ERROR);
    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['ready_for_database_write'])->toBeTrue();

    @unlink($importPath);
    @unlink($approvalPath);
    @unlink($pricedPath);
});

/** @return array<string, mixed> */
function neoxmedPricedTestImportMap(array $source): array
{
    $mapped = app(NeoxmedImportMapper::class)->mapCatalogue([
        'source' => 'neoxmed',
        'products' => [$source],
    ]);

    $mapped['mapping_structurally_valid'] = true;
    $mapped['database_audit'] = [
        'database_writes' => false,
        'errors' => [],
        'safe_for_future_import_implementation' => true,
        'summary' => [
            'existing_neoxmed_products' => 0,
            'slug_collisions' => 0,
            'variant_sku_collisions' => 0,
            'matched_category_slugs' => 1,
            'unmatched_category_slugs' => 0,
        ],
    ];

    return $mapped;
}

/** @return array<string, mixed> */
function neoxmedPricedTestProduct(): array
{
    return [
        'source' => 'neoxmed',
        'source_url' => 'https://neoxmed.com/ortezy-barku/',
        'source_locator' => 'https://neoxmed.com/ortezy-barku/#b-01',
        'source_code' => 'B-01',
        'source_qualifier' => null,
        'external_product_id' => 'B-01',
        'sku' => 'B-01',
        'slug' => 'b-01-kamizelka-stawu-barkowego',
        'name' => 'Kamizelka stawu barkowego',
        'categories' => ['Ortezy barku'],
        'source_category_paths' => [['Ortezy barku']],
        'description_text' => 'Kamizelka stabilizująca staw barkowy.',
        'description_html' => '<p>Kamizelka stabilizująca staw barkowy.</p>',
        'nfz_codes' => [],
        'size_note' => 'Rozmiary: S, M, L.',
        'images' => [[
            'url' => 'https://neoxmed.com/wp-content/uploads/B-01_resize-300x225.jpg',
            'alt' => 'B-01',
        ]],
        'size_chart_images' => [],
        'price_gross_amount' => null,
        'currency' => null,
        'availability' => null,
        'is_medical_device' => true,
    ];
}
