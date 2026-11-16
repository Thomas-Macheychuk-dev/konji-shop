<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Corradomed\CorradomedPriceList;
use App\Services\Corradomed\CorradomedProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ImportCorradomedProductsCommand extends Command
{
    protected $signature = 'corradomed:import
        {--from=scrapers/corradomed/products.json : Page data saved by corradomed:crawl (path under storage/app/private, or absolute).}
        {--price-list= : Price list JSON (default: '.CorradomedPriceList::DEFAULT_PATH.').}
        {--dry-run : Plan only. No database writes, no image downloads.}
        {--list : With --dry-run, print every product with its variants and prices.}
        {--page=* : Import only these page keys, e.g. "uchwyty?do=proste01".}
        {--no-images : Do not download images.}
        {--image-limit=10 : Images per product (0 = no limit).}
        {--archive-missing : After a full import, archive Corradomed products whose page is gone.}';

    protected $description = 'Import corradomed.pl products as drafts priced from the Corradomed price list (list net + 25%, list VAT, whole złoty).';

    public function handle(CorradomedProductImporter $importer): int
    {
        $path = $this->resolvePath((string) $this->option('from'));

        if (! is_file($path)) {
            $this->error('Corradomed page data not found: '.$path.'. Run corradomed:crawl first.');

            return self::FAILURE;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $priceListOption = $this->option('price-list');
            $priceList = CorradomedPriceList::fromFile(is_string($priceListOption) && $priceListOption !== '' ? $priceListOption : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $pages = array_values(array_filter((array) ($data['products'] ?? []), 'is_array'));
        $only = array_map('strval', (array) $this->option('page'));

        if ($only !== []) {
            $pages = array_values(array_filter($pages, fn (array $page): bool => in_array($page['page'] ?? '', $only, true)));
        }

        $plans = [];
        $unpriced = [];
        $usedListCodes = [];
        $noCodes = [];

        foreach ($pages as $page) {
            $plan = $importer->plan($page, $priceList);
            array_push($usedListCodes, ...$plan['list_codes']);

            foreach ($plan['unpriced_codes'] as $code) {
                $unpriced[] = $page['page'].': '.$code;
            }

            if ($plan['variants'] === []) {
                if (($page['codes'] ?? []) === []) {
                    $noCodes[] = $page['page'];
                }

                continue;
            }

            $plans[] = ['page' => $page, 'plan' => $plan];
        }

        $dryRun = (bool) $this->option('dry-run');
        $variantCount = array_sum(array_map(fn (array $row): int => count($row['plan']['variants']), $plans));

        $this->info('Price list from '.$priceList->validFrom().', shop price = list net + '.CorradomedPriceList::MARKUP_PERCENT.'%, list VAT, whole złoty.');
        $this->line('Pages: '.count($pages).', products to import: '.count($plans).', variants: '.$variantCount);
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));

        if ($dryRun && (bool) $this->option('list')) {
            foreach ($plans as $row) {
                $this->line('- '.$row['plan']['name'].'  ['.implode(' > ', $row['plan']['category']).']  '.$row['page']['page']);

                foreach ($row['plan']['variants'] as $variant) {
                    $this->line(sprintf(
                        '    %-26s %-34s list %4d zł net → %8s zł brutto (VAT %d%%)%s',
                        $variant['model'],
                        $variant['option'] ?? '',
                        $variant['list_net'],
                        number_format($variant['price_gross_amount'] / 100, 2, ',', ' '),
                        $variant['vat_rate'],
                        $variant['made_to_order'] ? '  *na zamówienie' : '',
                    ));
                }
            }
        }

        $this->line('Codes on corradomed.pl without a list price (left out): '.count($unpriced));

        foreach ($unpriced as $line) {
            $this->line('  '.$line);
        }

        if ($only === []) {
            $unused = $priceList->unusedCodes(array_values(array_unique($usedListCodes)));
            $this->line('List codes not sold on any corradomed.pl page (not imported): '.count($unused));
            $this->line('  '.implode(', ', $unused));
        }

        if ($dryRun) {
            $this->info('Dry run: no database writes, no image downloads.');

            return self::SUCCESS;
        }

        $importImages = ! (bool) $this->option('no-images');
        $imageLimit = max(0, (int) $this->option('image-limit')) ?: null;
        $failures = [];

        foreach ($plans as $index => $row) {
            $this->line(sprintf('[%d/%d] %s (%d variants)', $index + 1, count($plans), $row['plan']['name'], count($row['plan']['variants'])));

            try {
                $result = $importer->import($row['page'], $priceList, $importImages, $imageLimit);

                foreach ($result['warnings'] as $warning) {
                    $this->warn('  '.$warning);
                }

                $this->info(sprintf('  Product ID %d, variants: %d, images: %d', $result['product']->id, $result['product']->variants->count(), $result['product']->images->count()));
            } catch (Throwable $exception) {
                $failures[] = $row['page']['page'].' — '.$exception->getMessage();
                $this->error('  Failed: '.$exception->getMessage());
            }
        }

        $this->info('Imported products: '.(count($plans) - count($failures)).', failures: '.count($failures));

        if ((bool) $this->option('archive-missing')) {
            if ($only !== [] || $failures !== []) {
                $this->warn('Not archiving: --archive-missing needs a full import without failures.');
            } else {
                $archived = $importer->archiveMissing(array_column(array_column($plans, 'plan'), 'external_id'), false);
                $this->line('Archived Corradomed products: '.count($archived));

                foreach ($archived as $name) {
                    $this->line('  '.$name);
                }
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : Storage::disk('local')->path(ltrim($path, '/'));
    }
}
