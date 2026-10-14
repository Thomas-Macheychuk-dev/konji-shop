<?php

declare(strict_types=1);

namespace Tests\Feature\Herdegen;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EnrichHerdegenDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): Product
    {
        Storage::fake('local');
        $url = 'https://www.orteo.pl/test-product';
        $id = substr(hash('sha256', $url), 0, 32);
        Storage::disk('local')->put('scrapers/herdegen/analysis/listing-snapshot.json', json_encode([
            'source' => 'herdegen', 'analysis_type' => 'herdegen_offline_listing_snapshot',
            'summary' => ['products' => 1, 'commercially_approved' => 0],
            'products' => [[
                'external_id' => $id, 'source_url' => $url,
                'name' => 'Laska aluminiowa', 'brand' => 'Herdegen',
                'commercially_approved' => false, 'variant_candidates' => [],
            ]],
        ], JSON_THROW_ON_ERROR));
        return Product::query()->create([
            'external_source' => 'herdegen', 'external_id' => $id,
            'name' => 'Laska aluminiowa', 'slug' => 'herdegen-test-product',
            'status' => ProductStatus::DRAFT,
        ]);
    }

    public function test_dry_run_does_not_change_draft(): void
    {
        $product = $this->prepare();
        $this->artisan('herdegen:enrich-drafts')->expectsOutput('MODE=DRY_RUN')
            ->expectsOutput('UPDATED=0')->assertExitCode(0);
        self::assertNull($product->fresh()->description);
    }

    public function test_write_enriches_existing_draft_idempotently(): void
    {
        $product = $this->prepare();
        $this->artisan('herdegen:enrich-drafts', ['--write-drafts' => true])
            ->expectsOutput('UPDATED=1')->assertExitCode(0);
        self::assertNotNull($product->fresh()->description);
        self::assertSame(ProductStatus::DRAFT, $product->fresh()->status);
        $this->artisan('herdegen:enrich-drafts', ['--write-drafts' => true])
            ->expectsOutput('UPDATED=0')->assertExitCode(0);
    }

    public function test_rejects_active_products(): void
    {
        $product = $this->prepare();
        $product->update(['status' => ProductStatus::ACTIVE]);
        $this->artisan('herdegen:enrich-drafts', ['--write-drafts' => true])->assertExitCode(1);
    }
}
