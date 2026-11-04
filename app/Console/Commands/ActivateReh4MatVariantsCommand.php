<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductVariantStatus;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Activates draft Reh4Mat variants that have a price, so their products show a price and can be added to the cart.
 *
 * Variants are imported as drafts and the storefront only shows active ones. Unpriced and archived variants are
 * left alone. Every change is backed up so --rollback can return the variants to draft.
 */
final class ActivateReh4MatVariantsCommand extends Command
{
    protected $signature = 'reh4mat:activate-variants
        {--write : Activate the variants after writing a private backup. Otherwise preview only.}
        {--rollback= : Return the variants in a backup written by --write to draft.}';

    protected $description = 'Preview, apply or roll back activating priced draft Reh4Mat variants.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $drafts = ProductVariant::query()
            ->with('product:id,name,status')
            ->where('status', ProductVariantStatus::DRAFT->value)
            ->whereHas('product', fn ($query) => $query->where('external_source', 'reh4mat'))
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();

        [$priced, $unpriced] = $drafts->partition(fn (ProductVariant $variant): bool => $variant->grossPriceAmount() !== null);

        $this->line('=== REH4MAT VARIANT ACTIVATION ===');
        $this->line('TO_ACTIVATE='.$priced->count());
        $this->line('UNPRICED_LEFT_DRAFT='.$unpriced->count());

        foreach ($priced as $variant) {
            $this->line('ACTIVATE '.$this->label($variant).' gross '.number_format($variant->grossPriceAmount() / 100, 2, '.', ''));
        }

        foreach ($unpriced as $variant) {
            $this->line('UNPRICED '.$this->label($variant));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($priced->isEmpty()) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backupPath = 'catalogue-repairs/reh4mat-activate-variants-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'variant_ids' => $priced->modelKeys(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $written = $this->moveStatus($priced->modelKeys(), ProductVariantStatus::DRAFT, ProductVariantStatus::ACTIVE);

        $this->info('BACKUP='.$backupPath);
        $this->info('ACTIVATED='.$written);
        $this->info('REH4MAT_ACTIVATE_WRITE=PASS');

        return self::SUCCESS;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $variantIds = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR)['variant_ids'];
        $restored = $this->moveStatus($variantIds, ProductVariantStatus::ACTIVE, ProductVariantStatus::DRAFT);

        $this->info('RETURNED_TO_DRAFT='.$restored.' OF '.count($variantIds));
        $this->info('REH4MAT_ACTIVATE_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    /**
     * Move each variant still in the $from status to $to; variants changed since are left alone.
     *
     * @param  list<int>  $variantIds
     */
    private function moveStatus(array $variantIds, ProductVariantStatus $from, ProductVariantStatus $to): int
    {
        return DB::transaction(function () use ($variantIds, $from, $to): int {
            $written = 0;

            foreach (ProductVariant::query()->whereKey($variantIds)->lockForUpdate()->get() as $variant) {
                if ($variant->status !== $from) {
                    $this->warn('SKIPPED_CHANGED_SINCE variant='.$variant->id);

                    continue;
                }

                // Saved through the model so storefront cache invalidation observers run.
                $variant->status = $to;
                $variant->save();
                $written++;
            }

            return $written;
        });
    }

    private function label(ProductVariant $variant): string
    {
        $status = $variant->product->status;

        return sprintf(
            'product=%d variant=%d [%s] %s sku=%s',
            $variant->product_id,
            $variant->id,
            $status instanceof \BackedEnum ? $status->value : (string) $status,
            Str::limit((string) $variant->product->name, 70),
            $variant->sku ?? '-',
        );
    }
}
