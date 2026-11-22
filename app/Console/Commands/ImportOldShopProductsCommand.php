<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OldShop\OldShopProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Imports products read by oldshop:crawl as drafts at the old shop's prices. Publish them with oldshop:publish.
 */
final class ImportOldShopProductsCommand extends Command
{
    protected $signature = 'oldshop:import
        {--from= : Data saved by oldshop:crawl: a path in the project (resources/import-data/oldshop/thuasne.json) or under storage/app/private.}
        {--dry-run : Plan only. No database writes, no image downloads.}
        {--no-images : Do not download images.}';

    protected $description = 'Import products read from the old ortezka.pl shop as drafts, at the old shop prices.';

    public function handle(OldShopProductImporter $importer): int
    {
        $path = (string) $this->option('from');

        // A crawl committed with the code (resources/import-data/oldshop) or one saved on this server.
        if ($path !== '' && is_file(base_path($path))) {
            $json = (string) file_get_contents(base_path($path));
        } elseif ($path !== '' && Storage::disk('local')->exists($path)) {
            $json = (string) Storage::disk('local')->get($path);
        } else {
            $this->error('DATA_NOT_FOUND='.$path);

            return self::FAILURE;
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $pages = (array) ($data['products'] ?? []);
        $dryRun = (bool) $this->option('dry-run');
        $variants = 0;
        $unpriced = [];
        $categories = [];
        $imported = 0;
        $failures = [];

        foreach ($pages as $page) {
            $plan = $importer->plan($page);
            $category = ($plan['category'][0] ?? '(brak)').($plan['existing_category'] ? '' : ' [nowa pod '.($plan['brand'] ?? 'Inne produkty').']');
            $categories[$category] = ($categories[$category] ?? 0) + 1;

            if ($plan['variants'] === []) {
                $unpriced[] = $plan['name'];

                continue;
            }

            $variants += count($plan['variants']);

            if ($dryRun) {
                continue;
            }

            try {
                $result = $importer->import($page, importImages: ! $this->option('no-images'));
                $imported++;

                foreach ($result['warnings'] as $warning) {
                    $this->warn($warning);
                }
            } catch (Throwable $exception) {
                $failures[] = $plan['name'].': '.$exception->getMessage();
            }
        }

        $this->line('=== OLD SHOP IMPORT ===');
        $this->line(sprintf('PRODUCTS=%d VARIANTS=%d UNPRICED=%d', count($pages) - count($unpriced), $variants, count($unpriced)));
        ksort($categories);

        foreach ($categories as $category => $count) {
            $this->line(sprintf('CATEGORY %s: %d', $category, $count));
        }

        foreach ($unpriced as $name) {
            $this->line('UNPRICED '.$name);
        }

        if ($dryRun) {
            $this->line('MODE=DRY_RUN');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->error('FAILED '.$failure);
        }

        $this->info(sprintf('IMPORTED=%d FAILED=%d', $imported, count($failures)));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
