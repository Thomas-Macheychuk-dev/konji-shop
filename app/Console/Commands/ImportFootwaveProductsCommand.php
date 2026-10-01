<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VatRate;
use App\Services\Footwave\FootwaveProductImporter;
use App\Services\Footwave\FootwaveProductScraper;
use App\Services\Footwave\FootwaveStoreApiClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ImportFootwaveProductsCommand extends Command
{
    protected $signature = 'footwave:import
        {--product-id=* : Import specific FootWave WooCommerce product IDs.}
        {--limit= : Limit top-level catalogue products processed.}
        {--vat-rate= : Fallback VAT rate: 0, 5, 8 or 23.}
        {--vat-map= : JSON file mapping FootWave product ID to VAT rate.}
        {--dry-run : Fetch and validate without database writes.}
        {--no-images : Do not download or synchronize product images.}
        {--image-limit=10 : Maximum images per product.}
        {--show-failures : Print product failures.}';

    protected $description =
        'Import purchasable FootWave WooCommerce products and variants.';

    public function __construct(
        private readonly FootwaveStoreApiClient $client,
        private readonly FootwaveProductScraper $scraper,
        private readonly FootwaveProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $vatMap = $this->vatMap();
            $fallbackVat = $this->fallbackVat();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null
            ? max(1, (int) $this->option('limit'))
            : null;

        $ids = collect($this->option('product-id'))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $dryRun = (bool) $this->option('dry-run');
        $importImages = ! (bool) $this->option('no-images');
        $imageLimit = max(
            0,
            (int) $this->option('image-limit')
        );

        $prepared = 0;
        $imported = 0;
        $excluded = 0;
        $failed = [];

        try {
            if ($ids !== []) {
                $sourceProducts = [];

                foreach ($ids as $id) {
                    $sourceProducts[] =
                        $this->client->product($id);
                }
            } else {
                $sourceProducts =
                    $this->client->catalogue($limit);
            }
        } catch (Throwable $e) {
            $this->error(
                'FootWave catalogue request failed: '.
                $e->getMessage()
            );

            return self::FAILURE;
        }

        if ($limit !== null && $ids !== []) {
            $sourceProducts = array_slice(
                $sourceProducts,
                0,
                $limit
            );
        }

        $this->info(
            'FootWave source products: '.
            count($sourceProducts)
        );

        $this->line(
            'Mode: '.($dryRun ? 'dry-run' : 'write')
        );

        $this->line(
            'Images: '.
            ($importImages ? 'enabled' : 'skipped')
        );

        foreach ($sourceProducts as $sourceProduct) {
            $id = is_array($sourceProduct)
                ? (string) ($sourceProduct['id'] ?? 'unknown')
                : 'unknown';

            try {
                if (! is_array($sourceProduct)) {
                    throw new RuntimeException(
                        'Invalid source product payload.'
                    );
                }

                $scraped = $this->scraper->scrape(
                    $sourceProduct
                );

                if (($scraped['eligible'] ?? false) !== true) {
                    $reason = (string) (
                        $scraped['exclusion_reason']
                        ?? 'not_eligible'
                    );

                    if ($reason === 'not_purchasable') {
                        $excluded++;

                        $this->line(
                            'Excluded '.$id.
                            ': not purchasable'
                        );

                        continue;
                    }

                    throw new RuntimeException(
                        'Source product is not safely importable: '.
                        $reason
                    );
                }

                $vatRate =
                    $vatMap[$id]
                    ?? $fallbackVat;

                if (! $vatRate instanceof VatRate) {
                    throw new RuntimeException(
                        'No explicit VAT rate configured.'
                    );
                }

                $prepared++;

                $this->line(sprintf(
                    'Prepared %s [%s] - %d variants - VAT %d%%',
                    (string) (
                        $scraped['name']
                        ?? 'FootWave product'
                    ),
                    $id,
                    count($scraped['variants'] ?? []),
                    $vatRate->value,
                ));

                if ($dryRun) {
                    continue;
                }

                $product = $this->importer->import(
                    $scraped,
                    $vatRate,
                    $importImages,
                    $imageLimit,
                );

                $imported++;

                $this->info(sprintf(
                    'Imported product ID %d with %d variants.',
                    $product->id,
                    $product->variants->count(),
                ));
            } catch (Throwable $e) {
                $failed[$id] = $e->getMessage();

                $this->warn(
                    'Failed '.$id.': '.$e->getMessage()
                );
            }
        }

        $this->newLine();

        $this->info(
            'Prepared commerce products: '.$prepared
        );
        $this->info(
            'Excluded non-purchasable products: '.$excluded
        );

        if ($dryRun) {
            $this->info(
                'Dry run finished. No database writes were made.'
            );
        } else {
            $this->info(
                'Imported products: '.$imported
            );
        }

        if (
            (bool) $this->option('show-failures')
            && $failed !== []
        ) {
            $this->newLine();
            $this->warn('FootWave failures:');

            foreach ($failed as $id => $reason) {
                $this->line(
                    $id.' - '.$reason
                );
            }
        }

        $this->info(
            'Failures: '.count($failed)
        );

        return $failed === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return array<string, VatRate>
     */
    private function vatMap(): array
    {
        $option = trim(
            (string) ($this->option('vat-map') ?? '')
        );

        if ($option === '') {
            return [];
        }

        $path = str_starts_with($option, '/')
            ? $option
            : base_path($option);

        if (! is_file($path)) {
            throw new RuntimeException(
                'VAT map file not found: '.$path
            );
        }

        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'VAT map must contain a JSON object.'
            );
        }

        $map = [];

        foreach ($decoded as $productId => $rate) {
            if (
                ! is_scalar($productId)
                || ! is_numeric($rate)
            ) {
                throw new RuntimeException(
                    'Invalid FootWave VAT map entry.'
                );
            }

            $vatRate = VatRate::tryFrom(
                (int) $rate
            );

            if (! $vatRate instanceof VatRate) {
                throw new RuntimeException(
                    'Unsupported VAT rate ['.
                    (string) $rate.
                    '] for product '.
                    (string) $productId.'.'
                );
            }

            $map[(string) $productId] =
                $vatRate;
        }

        return $map;
    }

    private function fallbackVat(): ?VatRate
    {
        $option = $this->option('vat-rate');

        if ($option === null || trim((string) $option) === '') {
            return null;
        }

        if (! is_numeric($option)) {
            throw new RuntimeException(
                'VAT rate must be numeric.'
            );
        }

        $rate = VatRate::tryFrom((int) $option);

        if (! $rate instanceof VatRate) {
            throw new RuntimeException(
                'Unsupported VAT rate ['.
                (string) $option.
                '].'
            );
        }

        return $rate;
    }
}
