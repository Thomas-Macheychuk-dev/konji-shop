<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Sigvaris\SigvarisOfficialPricePlanner;
use App\Services\Sigvaris\SigvarisOfficialPriceWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('updates only planned Sigvaris variants, preserves unmatched variants, and is idempotent', function (): void {
    $writer = app(SigvarisOfficialPriceWriter::class);
    seedSigvarisOfficialPriceWriterCatalogue();
    $plan = sigvarisOfficialPriceWriterPlan();

    $beforeUnmatched = ProductVariant::query()
        ->where('external_variant_id', 'sigvaris-28035-unmatched')
        ->firstOrFail()
        ->only(['price_net_amount', 'price_gross_amount', 'vat_rate', 'currency']);

    $preflight = $writer->preflight($plan);

    expect($preflight['passed'])->toBeTrue()
        ->and($preflight['metrics']['variants_to_change'])->toBe(SigvarisOfficialPriceWriter::EXPECTED_MATCHED_VARIANT_COUNT)
        ->and($preflight['metrics']['vat_changes'])->toBe(1);

    $first = $writer->apply($plan);
    $priced = ProductVariant::query()->where('external_variant_id', 'sigvaris-106544-default')->firstOrFail();
    $unmatched = ProductVariant::query()->where('external_variant_id', 'sigvaris-28035-unmatched')->firstOrFail();

    expect($first['passed'])->toBeTrue()
        ->and($first['variants_updated'])->toBe(SigvarisOfficialPriceWriter::EXPECTED_MATCHED_VARIANT_COUNT)
        ->and($priced->price_net_amount)->toBe(840)
        ->and($priced->price_gross_amount)->toBe(907)
        ->and($priced->vat_rate)->toBe(VatRate::VAT_8)
        ->and($priced->currency)->toBe(Currency::PLN)
        ->and($unmatched->only(['price_net_amount', 'price_gross_amount', 'vat_rate', 'currency']))->toBe($beforeUnmatched);

    $second = $writer->apply($plan);

    expect($second['variants_updated'])->toBe(0)
        ->and($second['variants_unchanged'])->toBe(SigvarisOfficialPriceWriter::EXPECTED_MATCHED_VARIANT_COUNT)
        ->and($second['post_audit']['passed'])->toBeTrue();
});

it('guards the production official-price write with the whole-catalogue price fingerprint and is idempotent', function (): void {
    seedSigvarisOfficialPriceWriterCatalogue();
    $plan = sigvarisOfficialPriceWriterPlan();
    $relativePath = 'scrapers/sigvaris/official-price-production-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $planSha = hash_file('sha256', $path);

    expect($planSha)->toBeString()->toHaveLength(64);

    $writer = app(SigvarisOfficialPriceWriter::class);
    $beforeSha = $writer->cataloguePriceFingerprint();

    $dryRun = Artisan::call('sigvaris:production-apply-official-prices', [
        '--plan' => $relativePath,
        '--expected-plan-sha256' => $planSha,
        '--allow-non-production' => true,
    ]);
    $dryOutput = Artisan::output();

    expect($dryRun)->toBe(0)
        ->and($dryOutput)->toContain('Current catalogue price SHA-256: '.$beforeSha)
        ->and($dryOutput)->toContain('PASS: read-only production price preflight passed');

    $blocked = Artisan::call('sigvaris:production-apply-official-prices', [
        '--plan' => $relativePath,
        '--expected-plan-sha256' => $planSha,
        '--expected-current-price-sha256' => str_repeat('0', 64),
        '--write' => true,
        '--confirm-production-write' => 'APPLY-SIGVARIS-OFFICIAL-PRICES-PRODUCTION',
        '--acknowledge-unmatched' => 'LEAVE-5-UNMATCHED-SIGVARIS-PRODUCTS-UNCHANGED',
        '--allow-non-production' => true,
    ]);

    expect($blocked)->toBe(1)
        ->and(Artisan::output())->toContain('catalogue price fingerprint changed after preflight');

    $first = Artisan::call('sigvaris:production-apply-official-prices', [
        '--plan' => $relativePath,
        '--expected-plan-sha256' => $planSha,
        '--expected-current-price-sha256' => $beforeSha,
        '--write' => true,
        '--confirm-production-write' => 'APPLY-SIGVARIS-OFFICIAL-PRICES-PRODUCTION',
        '--acknowledge-unmatched' => 'LEAVE-5-UNMATCHED-SIGVARIS-PRODUCTS-UNCHANGED',
        '--allow-non-production' => true,
    ]);
    $firstOutput = Artisan::output();
    $afterSha = $writer->cataloguePriceFingerprint();

    expect($first)->toBe(0)
        ->and($afterSha)->not->toBe($beforeSha)
        ->and($firstOutput)->toContain('Planned prices verified: 14964/14964')
        ->and($firstOutput)->toContain('Unmatched variants preserved: 27/27')
        ->and($firstOutput)->toContain('PASS: production Sigvaris prices now match');

    $second = Artisan::call('sigvaris:production-apply-official-prices', [
        '--plan' => $relativePath,
        '--expected-plan-sha256' => $planSha,
        '--expected-current-price-sha256' => $afterSha,
        '--write' => true,
        '--confirm-production-write' => 'APPLY-SIGVARIS-OFFICIAL-PRICES-PRODUCTION',
        '--acknowledge-unmatched' => 'LEAVE-5-UNMATCHED-SIGVARIS-PRODUCTS-UNCHANGED',
        '--allow-non-production' => true,
    ]);
    $secondOutput = Artisan::output();

    expect($second)->toBe(0)
        ->and($secondOutput)->toContain('Variants updated: 0')
        ->and($secondOutput)->toContain('Variants unchanged: 14964')
        ->and($writer->cataloguePriceFingerprint())->toBe($afterSha);

    @unlink($path);
});

function seedSigvarisOfficialPriceWriterCatalogue(): void
{
    $matchedProductIds = [];

    for ($i = 0; $i < SigvarisOfficialPriceWriter::EXPECTED_MATCHED_PRODUCT_COUNT; $i++) {
        $externalId = $i === 0 ? '106544' : 'matched-'.$i;
        $product = Product::query()->create([
            'name' => 'Matched '.$externalId,
            'slug' => 'matched-'.$i,
            'external_source' => 'sigvaris',
            'external_id' => $externalId,
            'status' => ProductStatus::DRAFT,
            'published_at' => null,
        ]);
        $matchedProductIds[] = [$externalId, $product->id];
    }

    $unmatchedProducts = [];
    foreach (SigvarisOfficialPriceWriter::UNMATCHED_PRODUCT_IDS as $index => $externalId) {
        $product = Product::query()->create([
            'name' => 'Unmatched '.$externalId,
            'slug' => 'unmatched-'.$index,
            'external_source' => 'sigvaris',
            'external_id' => $externalId,
            'status' => ProductStatus::DRAFT,
            'published_at' => null,
        ]);
        $unmatchedProducts[$externalId] = $product;
    }

    $matchedVariantCount = SigvarisOfficialPriceWriter::EXPECTED_MATCHED_VARIANT_COUNT;
    for ($i = 0; $i < $matchedVariantCount; $i++) {
        [$externalId, $productId] = $matchedProductIds[$i % count($matchedProductIds)];
        $externalVariantId = $i === 0 ? 'sigvaris-106544-default' : 'planned-'.$i;
        $sku = $i === 0 ? 'SIGVARIS-106544' : 'PLANNED-'.$i;

        ProductVariant::query()->create([
            'product_id' => $productId,
            'external_variant_id' => $externalVariantId,
            'sku' => $sku,
            'status' => ProductVariantStatus::DRAFT,
            'price_net_amount' => $i === 0 ? 3984 : 1000,
            'price_gross_amount' => $i === 0 ? 4900 : 1230,
            'currency' => Currency::PLN,
            'vat_rate' => $i === 0 ? VatRate::VAT_23 : VatRate::VAT_23,
            'stock_status' => StockStatus::IN_STOCK,
            'is_default' => false,
        ]);
    }

    $remaining = SigvarisOfficialPriceWriter::EXPECTED_UNMATCHED_VARIANT_COUNT;
    $counter = 0;
    foreach (SigvarisOfficialPriceWriter::UNMATCHED_PRODUCT_IDS as $externalId) {
        $product = $unmatchedProducts[$externalId];
        $count = $externalId === '28035' || $externalId === '106565' ? 12 : 1;
        for ($j = 0; $j < $count; $j++) {
            $variantExternalId = $counter === 0 ? 'sigvaris-28035-unmatched' : 'unmatched-'.$counter;
            ProductVariant::query()->create([
                'product_id' => $product->id,
                'external_variant_id' => $variantExternalId,
                'sku' => 'UNMATCHED-'.$counter,
                'status' => ProductVariantStatus::DRAFT,
                'price_net_amount' => 2000 + $counter,
                'price_gross_amount' => 2160 + $counter,
                'currency' => Currency::PLN,
                'vat_rate' => VatRate::VAT_8,
                'stock_status' => StockStatus::IN_STOCK,
                'is_default' => false,
            ]);
            $counter++;
            $remaining--;
        }
    }

    expect($remaining)->toBe(0);
}

/** @return array<string, mixed> */
function sigvarisOfficialPriceWriterPlan(): array
{
    $variants = [];
    for ($i = 0; $i < SigvarisOfficialPriceWriter::EXPECTED_MATCHED_VARIANT_COUNT; $i++) {
        $productIndex = $i % SigvarisOfficialPriceWriter::EXPECTED_MATCHED_PRODUCT_COUNT;
        $productExternalId = $productIndex === 0 ? '106544' : 'matched-'.$productIndex;
        $externalVariantId = $i === 0 ? 'sigvaris-106544-default' : 'planned-'.$i;
        $sku = $i === 0 ? 'SIGVARIS-106544' : 'PLANNED-'.$i;
        $baseNet = $i === 0 ? 700 : 1000;
        $vat = $i === 0 ? 8 : 23;
        $sellingNet = (int) round($baseNet * 1.20);
        $sellingGross = (int) round($baseNet * (1 + ($vat / 100)) * 1.20);
        $variants[] = [
            'product_external_id' => $productExternalId,
            'external_variant_id' => $externalVariantId,
            'sku' => $sku,
            'base_net_minor' => $baseNet,
            'vat_rate' => $vat,
            'markup_percent' => 20,
            'selling_net_minor' => $sellingNet,
            'selling_gross_minor' => $sellingGross,
            'currency' => 'PLN',
            'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf',
            'source_label' => $i === 0 ? '361504' : 'fixture',
        ];
    }

    return [
        'version' => 1,
        'source' => 'sigvaris',
        'price_list_effective_date' => '2026-01-21',
        'formula' => 'selling_gross = round(base_net * (1 + VAT) * 1.20, 2)',
        'markup_percent' => 20,
        'database_writes' => false,
        'matched_product_count' => SigvarisOfficialPriceWriter::EXPECTED_MATCHED_PRODUCT_COUNT,
        'matched_variant_count' => SigvarisOfficialPriceWriter::EXPECTED_MATCHED_VARIANT_COUNT,
        'unmatched_product_count' => SigvarisOfficialPriceWriter::EXPECTED_UNMATCHED_PRODUCT_COUNT,
        'unmatched_variant_count' => SigvarisOfficialPriceWriter::EXPECTED_UNMATCHED_VARIANT_COUNT,
        'error_count' => 0,
        'ready_for_price_write_implementation' => false,
        'variants' => $variants,
        'unmatched_products' => [
            ['external_id' => '28035', 'name' => 'A', 'variant_count' => 12, 'reason' => 'review'],
            ['external_id' => '106565', 'name' => 'B', 'variant_count' => 12, 'reason' => 'review'],
            ['external_id' => '27276', 'name' => 'C', 'variant_count' => 1, 'reason' => 'review'],
            ['external_id' => '27275', 'name' => 'D', 'variant_count' => 1, 'reason' => 'review'],
            ['external_id' => '27268', 'name' => 'E', 'variant_count' => 1, 'reason' => 'review'],
        ],
        'errors' => [],
        'import_map_sha256' => SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256,
        'source_fingerprints' => [
            'import_map' => SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256,
            'Cennik_Sigvaris_podstawowy_21.01.2026.pdf' => 'a5c41a3459ebf12d8f64f0a304089183a20d9b004e8fd931094485d8e196d2ed',
            'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf' => 'a7ed53461e54af8bfe050fd3954264b87ff53e82dfcaed1358c046909d06ebde',
            'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf' => '15ac7f125888476289b05a776724d25877c07c4d053cf87b8a7d9848d09e7de9',
            'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf' => '5b6f3989f03e915f5c0e816a9fda9a7667b2778ab5e20e8a8ae6be3ccce8ff2a',
            'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf' => 'e51e9756480f52c3266996caf1e297834e6410ce694757bede0944d149000c4f',
        ],
    ];
}
