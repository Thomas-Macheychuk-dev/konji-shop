<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VatRate;
use App\Services\Sanipur\SanipurProductGrouper;
use App\Services\Sanipur\SanipurProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ImportSanipurProductsCommand extends Command
{
    protected $signature = 'sanipur:import
        {--from=scrapers/sanipur/products.json : Product data saved by sanipur:crawl (path under storage/app/private, or absolute).}
        {--dry-run : Group and validate only. No database writes, no image downloads.}
        {--list : With --dry-run, print every product with its variants and prices.}
        {--vat-rate= : VAT rate for products (0, 5, 8, 23). Required.}
        {--medical-vat-rate= : VAT rate for pages that say "wyrób medyczny". Defaults to --vat-rate.}
        {--limit= : Import at most this many grouped products.}
        {--offset=0 : Skip this many grouped products first.}
        {--no-images : Do not download images.}
        {--image-limit=10 : Images per product (0 = no limit).}
        {--show-failures : Print every failed product at the end.}';

    protected $description = 'Import sanipur.eu products as draft products, one variant per sanipur.eu page with its price.';

    public function handle(SanipurProductGrouper $grouper, SanipurProductImporter $importer): int
    {
        $path = $this->resolvePath((string) $this->option('from'));

        if (! is_file($path)) {
            $this->error('Sanipur data file not found: '.$path.'. Run sanipur:crawl first.');

            return self::FAILURE;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->error('Invalid Sanipur data file: '.$exception->getMessage());

            return self::FAILURE;
        }

        $pages = is_array($data['products'] ?? null) ? $data['products'] : (array_is_list($data) ? $data : []);
        $groups = $grouper->group($pages);
        $offset = max(0, (int) $this->option('offset'));
        $limit = is_string($this->option('limit')) && (int) $this->option('limit') > 0 ? (int) $this->option('limit') : null;
        $selected = array_slice($groups, $offset, $limit);

        $vatRate = $this->vatOption('vat-rate');
        $medicalVatRate = $this->vatOption('medical-vat-rate');

        if ($vatRate === false || $medicalVatRate === false) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');
        $imageLimit = max(0, (int) $this->option('image-limit')) ?: null;

        $this->info('Sanipur data: '.$path);
        $this->line('Product pages: '.count($pages));
        $this->line('Grouped products: '.count($groups).' (selected '.count($selected).')');
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('VAT: '.($vatRate?->value ?? '—').'% / medical devices: '.(($medicalVatRate ?? $vatRate)?->value ?? '—').'%');

        if ($dryRun) {
            return $this->dryRun($selected, $importer, $vatRate, $medicalVatRate);
        }

        $imported = 0;
        $failures = [];

        foreach ($selected as $index => $group) {
            $this->line(sprintf('[%d/%d] %s (%d variants)', $index + 1, count($selected), $group['name'], count($group['variant_candidates'])));

            try {
                $result = $importer->import($group, $vatRate, $medicalVatRate, $importImages, $imageLimit);
                $imported++;

                foreach ($result['warnings'] as $warning) {
                    $this->warn('  '.$warning);
                }

                $this->info(sprintf('  Product ID %d, variants: %d, images: %d', $result['product']->id, $result['product']->variants->count(), $result['product']->images->count()));
            } catch (Throwable $exception) {
                $failures[] = $group['name'].' — '.($group['source_url'] ?? '').' — '.$exception->getMessage();
                $this->error('  Failed: '.$exception->getMessage());
            }
        }

        $this->info('Imported products: '.$imported);
        $this->line('Failures: '.count($failures));

        if ((bool) $this->option('show-failures')) {
            foreach ($failures as $failure) {
                $this->line('- '.$failure);
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function dryRun(array $groups, SanipurProductImporter $importer, ?VatRate $vatRate, ?VatRate $medicalVatRate): int
    {
        $variants = 0;
        $medical = 0;
        $missingVat = 0;
        $categories = [];
        $brands = [];
        $prices = [];

        foreach ($groups as $group) {
            $categories[implode(' > ', SanipurProductImporter::categoryPath($group['breadcrumbs'] ?? []))] = true;
            $brands[(string) ($group['brand'] ?? '—')] = ($brands[(string) ($group['brand'] ?? '—')] ?? 0) + 1;

            foreach ($group['variant_candidates'] as $candidate) {
                $variants++;
                $prices[] = (float) $candidate['price_gross_amount'];
                $medical += (int) $candidate['is_medical_device'];

                try {
                    $importer->resolveVatRate($candidate, $vatRate, $medicalVatRate);
                } catch (Throwable) {
                    $missingVat++;
                }
            }

            if ((bool) $this->option('list')) {
                $this->line('- '.$group['name'].'  ['.implode(' > ', SanipurProductImporter::categoryPath($group['breadcrumbs'] ?? [])).']');

                foreach ($group['variant_candidates'] as $candidate) {
                    $this->line(sprintf('    %s  %s zł  %s', $candidate['source_sku'] ?? $candidate['external_variant_id'], number_format((float) $candidate['price_gross_amount'], 2, ',', ' '), $candidate['label'] ?? ''));
                }
            }
        }

        arsort($brands);
        ksort($categories);

        $this->info('Dry-run summary. No database writes were made. No images were downloaded.');
        $this->line('Products to import/update: '.count($groups));
        $this->line('Variants to create/update: '.$variants);
        $this->line('Variants marked as medical devices: '.$medical);
        $this->line('Variants without a VAT rate: '.$missingVat);
        $this->line('Price range: '.($prices === [] ? '—' : number_format(min($prices), 2, ',', ' ').' – '.number_format(max($prices), 2, ',', ' ').' zł'));
        $this->line('Brands: '.implode(', ', array_map(fn ($brand, $count) => $brand.' ('.$count.')', array_keys($brands), $brands)));
        $this->line('Categories ('.count($categories).') under "'.SanipurProductImporter::ROOT_CATEGORY['name'].'":');

        foreach (array_keys($categories) as $category) {
            $this->line('  '.$category);
        }

        if ($missingVat > 0) {
            $this->warn('The import will refuse products without a VAT rate. Pass --vat-rate (and --medical-vat-rate).');
        }

        return self::SUCCESS;
    }

    private function vatOption(string $name): VatRate|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $rate = ctype_digit((string) $value) ? VatRate::tryFrom((int) $value) : null;

        if ($rate === null) {
            $this->error('--'.$name.' must be one of: 0, 5, 8, 23.');

            return false;
        }

        return $rate;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : Storage::disk('local')->path(ltrim($path, '/'));
    }
}
