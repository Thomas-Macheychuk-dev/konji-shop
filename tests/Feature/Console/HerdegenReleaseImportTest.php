<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

final class HerdegenReleaseImportTest extends TestCase
{
    use RefreshDatabase;

    private const ARCHIVE_SHA256 =
        'ce1258c94cd7a973d753761f6259225cdb9978aadc0cd575c9003d33814e755c';

    private const CATEGORY_MAP = [
        'sprzet-pomocniczy-i-sanitarny-sprzet-sanitarny-lawki-nawannowe'
            => 'lawki-nawannowe',
    ];

    private function archivePath(): string
    {
        $configuredPath = getenv('HERDEGEN_RELEASE_ARCHIVE');
        $path = is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : storage_path(
                'app/private/scrapers/herdegen/releases/herdegen18d4-transfer.zip'
            );

        // This checksum-pinned release is an external operational artifact,
        // not a fixture included in a fresh checkout. Never skip an explicitly
        // configured archive: a missing CI/local input must fail visibly.
        if (($configuredPath === false || $configuredPath === '') && ! is_file($path)) {
            self::markTestSkipped(
                'External Herdegen release archive unavailable; set HERDEGEN_RELEASE_ARCHIVE to run this release integration test.'
            );
        }

        self::assertFileExists($path);
        self::assertSame(self::ARCHIVE_SHA256, hash_file('sha256', $path));

        return $path;
    }

    private function manifest(): array
    {
        $zip = new ZipArchive();

        self::assertTrue(
            $zip->open($this->archivePath()) === true,
            'Cannot open Herdegen release ZIP'
        );

        try {
            $manifestEntries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (is_string($name) && str_ends_with($name, '.json')) {
                    $manifestEntries[] = $name;
                }
            }

            self::assertCount(1, $manifestEntries);

            $json = $zip->getFromName($manifestEntries[0]);

            self::assertIsString($json);

            return json_decode(
                $json,
                true,
                flags: JSON_THROW_ON_ERROR
            );
        } finally {
            $zip->close();
        }
    }

    private function prepareEnvironment(): void
    {
        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(
            ':memory:',
            config('database.connections.sqlite.database')
        );

        // Establish the fake disk before changing its driver configuration.
        // Laravel's filesystem manager caches the fake adapter.
        Storage::fake('public');

        // Satisfy the importer's explicit S3 write gate without AWS access.
        config()->set('filesystems.disks.public.driver', 's3');

        $slugs = [];

        foreach ($this->manifest()['products'] as $product) {
            foreach ($product['categories'] as $category) {
                $slug = $category['slug'];

                $slugs[self::CATEGORY_MAP[$slug] ?? $slug] = true;
            }
        }

        self::assertCount(13, $slugs);

        foreach (array_keys($slugs) as $slug) {
            Category::query()->create([
                'name' => $slug === 'lawki-nawannowe'
                    ? 'Ławki nawannowe'
                    : $slug,
                'slug' => $slug,
                'status' => CategoryStatus::ACTIVE,
            ]);
        }
    }

    private function importOptions(bool $write = false): array
    {
        $options = [
            '--archive' => $this->archivePath(),
        ];

        if ($write) {
            $options['--write-drafts'] = true;
            $options['--acknowledge-production-backup'] = true;
        }

        return $options;
    }

    private function assertImportedCatalogue(): void
    {
        self::assertSame(
            46,
            Product::query()->where('external_source', 'herdegen')->count()
        );

        self::assertSame(79, ProductVariant::query()->count());
        self::assertSame(22, Attribute::query()->count());
        self::assertSame(55, AttributeValue::query()->count());

        self::assertSame(
            65,
            ProductImage::query()->count()
                + ProductAttributeValueImage::query()->count()
        );

        self::assertSame(
            46,
            Product::query()
                ->where('status', ProductStatus::DRAFT)
                ->whereNull('published_at')
                ->count()
        );

        self::assertSame(
            79,
            ProductVariant::query()
                ->where('status', ProductVariantStatus::DRAFT)
                ->where('stock_status', StockStatus::OUT_OF_STOCK)
                ->count()
        );

        self::assertSame(
            2,
            Category::query()
                ->where('slug', 'lawki-nawannowe')
                ->firstOrFail()
                ->products()
                ->where('external_source', 'herdegen')
                ->count()
        );

        $media = $this->manifest()['media'];

        self::assertCount(64, $media);
        self::assertCount(64, Storage::disk('public')->allFiles('products/herdegen'));

        foreach ($media as $item) {
            $path = $item['path'];

            Storage::disk('public')->assertExists($path);

            $content = Storage::disk('public')->get($path);

            self::assertSame($item['size'], strlen($content));
            self::assertSame($item['sha256'], hash('sha256', $content));
        }

        $images = ProductImage::query()->get()->concat(
            ProductAttributeValueImage::query()->get()
        );

        foreach ($images as $image) {
            self::assertSame(
                Storage::disk('public')->size($image->path),
                $image->file_size
            );
            self::assertSame('public', $image->disk);
        }

        self::assertSame(
            79,
            ProductVariant::query()
                ->whereNotNull('price_gross_amount')
                ->where('price_gross_amount', '>', 0)
                ->count()
        );
    }

    public function test_dry_run_validates_archive_without_writing(): void
    {
        $this->prepareEnvironment();

        $this->artisan(
            'herdegen:import-release',
            $this->importOptions()
        )
            ->expectsOutput('ATTRIBUTE_COMPATIBILITY=PASS')
            ->expectsOutput('HERDEGEN18E3=PASS_DRY_RUN')
            ->assertExitCode(0);

        self::assertSame(0, Product::query()->count());
        self::assertSame(0, ProductVariant::query()->count());
        self::assertSame(0, Attribute::query()->count());
        self::assertSame(
            [],
            Storage::disk('public')->allFiles('products/herdegen')
        );
    }

    public function test_imports_complete_draft_catalogue(): void
    {
        $this->prepareEnvironment();

        $this->artisan(
            'herdegen:import-release',
            $this->importOptions(true)
        )
            ->expectsOutput('HERDEGEN18E3=PASS_DRAFT_IMPORT')
            ->assertExitCode(0);

        $this->assertImportedCatalogue();
    }

    public function test_rejects_repeated_import_without_modification(): void
    {
        $this->prepareEnvironment();

        $this->artisan(
            'herdegen:import-release',
            $this->importOptions(true)
        )->assertExitCode(0);

        $this->artisan(
            'herdegen:import-release',
            $this->importOptions(true)
        )
            ->expectsOutput(
                'HERDEGEN18E3=FAIL: Herdegen products already exist; refusing overwrite'
            )
            ->assertExitCode(1);

        $this->assertImportedCatalogue();
    }

    public function test_database_failure_rolls_back_all_catalogue_records(): void
    {
        $this->prepareEnvironment();

        // Induce a genuine SQLite insert failure after media uploading.
        // The production database is never accessed.
        DB::statement(<<<'SQL'
            CREATE TRIGGER herdegen_test_reject_image
            BEFORE INSERT ON product_images
            BEGIN
                SELECT RAISE(ABORT, 'forced Herdegen image failure');
            END
        SQL);

        try {
            $this->artisan(
                'herdegen:import-release',
                $this->importOptions(true)
            )
                ->expectsOutputToContain('HERDEGEN18E3=FAIL:')
                ->assertExitCode(1);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS herdegen_test_reject_image');
        }

        self::assertSame(0, Product::query()->count());
        self::assertSame(0, ProductVariant::query()->count());
        self::assertSame(0, Attribute::query()->count());
        self::assertSame(0, AttributeValue::query()->count());
        self::assertSame(0, ProductImage::query()->count());
        self::assertSame(0, ProductAttributeValueImage::query()->count());

        // S3 is outside the DB transaction. Uploaded objects remain.
        // This is intentional and must be handled in the recovery plan.
        self::assertCount(
            64,
            Storage::disk('public')->allFiles('products/herdegen')
        );
    }
}
