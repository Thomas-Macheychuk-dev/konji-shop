<?php

declare(strict_types=1);

namespace Tests\Feature\Herdegen;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RepairHerdegenSeoTitlesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Francuski WÓZEK INWALIDZKI - Aluminiowy (Albatros)';

    private function product(string $seoTitle, string $source = 'herdegen', string $slug = 'herdegen-albatros'): Product
    {
        return Product::query()->create([
            'external_source' => $source, 'external_id' => $slug,
            'name' => self::NAME, 'slug' => $slug, 'seo_title' => $seoTitle,
            'status' => ProductStatus::ACTIVE,
        ]);
    }

    private function truncated(): string
    {
        // What herdegen:enrich-drafts used to store: "… (Albatros) | Herdege".
        return Str::limit(self::NAME.' | Herdegen', 60, '');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $product = $this->product($this->truncated());
        $this->artisan('herdegen:repair-seo-titles')
            ->expectsOutput('TRUNCATED_TITLES=1')->expectsOutput('MODE=READ_ONLY')->assertExitCode(0);
        self::assertSame($this->truncated(), $product->fresh()->seo_title);
    }

    public function test_write_restores_the_brand_idempotently_and_rolls_back(): void
    {
        Storage::fake('local');
        $product = $this->product($this->truncated());

        $this->artisan('herdegen:repair-seo-titles', ['--write' => true])
            ->expectsOutput('WRITTEN_TITLES=1')->assertExitCode(0);
        self::assertSame(self::NAME.' | Herdegen', $product->fresh()->seo_title);

        $this->artisan('herdegen:repair-seo-titles', ['--write' => true])
            ->expectsOutput('TRUNCATED_TITLES=0')->expectsOutput('NOTHING_TO_WRITE')->assertExitCode(0);

        $backup = Storage::disk('local')->files('seo-repairs')[0];
        $this->artisan('herdegen:repair-seo-titles', ['--rollback' => $backup])
            ->expectsOutput('RESTORED_TITLES=1 OF 1')->assertExitCode(0);
        self::assertSame($this->truncated(), $product->fresh()->seo_title);
    }

    public function test_leaves_hand_edited_and_other_supplier_titles_alone(): void
    {
        Storage::fake('local');
        $edited = $this->product('Wózek inwalidzki Albatros – lekki, aluminiowy | Herdegen');
        $short = $this->product('Francuski WÓZEK', slug: 'herdegen-short');
        $other = $this->product($this->truncated(), 'vermeiren', 'vermeiren-albatros');

        $this->artisan('herdegen:repair-seo-titles', ['--write' => true])
            ->expectsOutput('TRUNCATED_TITLES=0')->assertExitCode(0);

        self::assertSame('Wózek inwalidzki Albatros – lekki, aluminiowy | Herdegen', $edited->fresh()->seo_title);
        self::assertSame('Francuski WÓZEK', $short->fresh()->seo_title);
        self::assertSame($this->truncated(), $other->fresh()->seo_title);
    }
}
