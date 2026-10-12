<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Befado\BefadoProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class UpdateBefadoSeoTitlesCommand extends Command
{
    protected $signature = 'befado:seo-titles
        {--write : Apply the validated title changes. Otherwise preview only.}';

    protected $description = 'Preview or update Befado SEO titles without changing other product data.';

    public function __construct(
        private readonly BefadoProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $products = Product::query()
            ->where('external_source', 'befado')
            ->orderBy('id')
            ->get();

        $rows = [];
        $titles = [];
        $errors = [];

        if ($products->count() !== 270) {
            $errors[] = 'Expected exactly 270 Befado products.';
        }

        foreach ($products as $product) {
            $sku = trim((string) $product->external_parent_sku);

            if ($sku === '') {
                $errors[] = 'Missing SKU: '.$product->external_id;

                continue;
            }

            if ($product->status->value !== 'active' && $this->option('write')) {
                $errors[] = 'Non-active product: '.$product->external_id;
            }

            $title = $this->importer->seoTitle(
                (string) $product->name,
                $sku,
            );

            $key = mb_strtolower($title);

            if (isset($titles[$key])) {
                $errors[] = 'Duplicate title: '.$title;
            }

            $titles[$key] = true;

            $rows[] = [
                'id' => $product->id,
                'external_id' => $product->external_id,
                'old_title' => $product->seo_title,
                'new_title' => $title,
            ];
        }

        $changes = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['old_title'] !== $row['new_title'],
        ));

        $over60 = count(array_filter(
            $rows,
            fn (array $row): bool => mb_strlen($row['new_title']) > 60,
        ));

        $this->line('=== BEFADO SEO TITLE AUDIT ===');
        $this->line('PRODUCTS='.$products->count());
        $this->line('UNIQUE_TITLES='.count($titles));
        $this->line('CHANGED_TITLES='.count($changes));
        $this->line('TITLES_OVER_60='.$over60);
        $this->line('ERRORS='.count($errors));

        foreach (array_slice($changes, 0, 15) as $row) {
            $this->line(
                $row['external_id'].': '.
                $row['old_title'].' => '.$row['new_title']
            );
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        if ($errors !== [] || count($rows) !== 270 || count($titles) !== 270) {
            $this->error('BEFADO_SEO_TITLE_GATE=FAIL');

            return self::FAILURE;
        }

        $this->info('BEFADO_SEO_TITLE_GATE=PASS');

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        $backupPath = 'scrapers/befado/backups/seo-titles-before-'.
            gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.json';

        $json = json_encode(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $disk = Storage::disk('local');

        if (! $disk->put($backupPath, $json)
            || $disk->get($backupPath) !== $json) {
            throw new RuntimeException('SEO title backup verification failed.');
        }

        $this->line('BACKUP_PATH='.$backupPath);
        $this->line('BACKUP_SHA256='.hash('sha256', $json));

        DB::transaction(function () use ($changes): void {
            foreach ($changes as $row) {
                $updated = Product::query()
                    ->whereKey($row['id'])
                    ->where('external_source', 'befado')
                    ->where('seo_title', $row['old_title'])
                    ->update(['seo_title' => $row['new_title']]);

                if ($updated !== 1) {
                    throw new RuntimeException(
                        'Concurrent modification detected: '.$row['external_id']
                    );
                }
            }
        });

        $this->info('UPDATED_TITLES='.count($changes));
        $this->info('BEFADO_SEO_TITLE_UPDATE=PASS');

        return self::SUCCESS;
    }
}
