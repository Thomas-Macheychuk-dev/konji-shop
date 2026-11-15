<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes the Timago dental models (Nissin teeth models, phantoms and endodontic blocks from Timago's
 * "Stomatologia / Modele dentystyczne / Fantomy" category) off the shop by archiving them; the shop does
 * not sell them (owner's feedback, 15 November 2026). Archived products stay in the admin and the rollback
 * restores each product's earlier status.
 */
final class ArchiveTimagoDentalProductsCommand extends Command
{
    /**
     * Timago product ids from its "Stomatologia / Modele dentystyczne / Fantomy" category: the teeth models,
     * phantoms and endodontic blocks (516-535) and the dental carpule needles (740). The same category's
     * disinfectant (AHD 1000, id 1000) and Cutanplast gel dressings (741) are not dental-only and stay on sale.
     */
    public const EXTERNAL_IDS = [
        '516', '517', '518', '519', '520', '521', '522', '523', '524', '525',
        '526', '527', '528', '529', '530', '531', '532', '533', '534', '535', '740',
    ];

    protected $signature = 'timago:archive-dental
        {--write : Archive the products after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the product statuses from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back archiving the Timago dental models.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $products = Product::query()
            ->where('external_source', 'timago')
            ->whereIn('external_id', self::EXTERNAL_IDS)
            ->orderBy('id')
            ->get(['id', 'name', 'status', 'external_id']);
        $toArchive = $products->reject(fn (Product $product): bool => $product->status === ProductStatus::ARCHIVED);

        $this->line('=== TIMAGO DENTAL MODELS ===');
        $this->line('FOUND='.$products->count().' TO_ARCHIVE='.$toArchive->count().' ALREADY_ARCHIVED='.($products->count() - $toArchive->count()));

        foreach ($toArchive as $product) {
            $this->line(sprintf('ARCHIVE product=%d [%s] %s', $product->id, $product->status->value, $product->name));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($toArchive->isEmpty()) {
            return self::SUCCESS;
        }

        $backupPath = 'catalogue-repairs/timago-dental-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'products' => $toArchive->map(fn (Product $product): array => ['id' => $product->id, 'status' => $product->status->value])->values()->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($toArchive): void {
            foreach ($toArchive as $product) {
                // Saved through the model so storefront cache invalidation observers run.
                $product->status = ProductStatus::ARCHIVED;
                $product->save();
            }
        });

        $this->info('BACKUP='.$backupPath);
        $this->info('ARCHIVED='.$toArchive->count());

        return self::SUCCESS;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $restored = 0;

        foreach ($backup['products'] as $row) {
            $product = Product::query()->find($row['id']);

            if ($product === null || $product->status !== ProductStatus::ARCHIVED) {
                continue;
            }

            $product->status = ProductStatus::from($row['status']);
            $product->save();
            $restored++;
        }

        $this->info('RESTORED='.$restored.' OF '.count($backup['products']));

        return self::SUCCESS;
    }
}
