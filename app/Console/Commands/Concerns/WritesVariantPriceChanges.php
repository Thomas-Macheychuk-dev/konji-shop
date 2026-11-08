<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Enums\VatRate;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Backup, write and rollback of variant price changes for the price-list commands.
 *
 * Each change holds variant_id, old_net, old_gross, new_net and new_gross, and optionally old_vat and new_vat
 * when the VAT rate changes too; writes and rollbacks only touch variants whose prices (and VAT rate) still
 * equal the side being replaced.
 */
trait WritesVariantPriceChanges
{
    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $context
     */
    private function writeChangesWithBackup(string $backupName, array $changes, array $context): void
    {
        $backupPath = 'catalogue-repairs/'.$backupName.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            ...$context,
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $written = $this->writePrices($changes, 'old', 'new');

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN_PRICES='.$written);
    }

    private function rollbackChanges(string $backupPath): bool
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return false;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $restored = $this->writePrices($backup['changes'], 'new', 'old');

        $this->info('RESTORED_PRICES='.$restored.' OF '.count($backup['changes']));

        return true;
    }

    /**
     * Write the $to prices over each variant whose prices still equal $from; variants edited since are left alone.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function writePrices(array $changes, string $from, string $to): int
    {
        return DB::transaction(function () use ($changes, $from, $to): int {
            $written = 0;

            foreach ($changes as $change) {
                $variant = ProductVariant::query()->lockForUpdate()->find($change['variant_id']);

                if ($variant === null) {
                    throw new RuntimeException('Variant disappeared: '.$change['variant_id']);
                }

                $currentNet = $variant->price_net_amount === null ? null : (int) $variant->price_net_amount;
                $changesVat = array_key_exists($from.'_vat', $change);
                $currentVat = $variant->vat_rate instanceof VatRate ? $variant->vat_rate->value : null;

                if ($currentNet !== $change[$from.'_net']
                    || $variant->price_gross_amount !== $change[$from.'_gross']
                    || ($changesVat && $currentVat !== $change[$from.'_vat'])) {
                    $this->warn('SKIPPED_CHANGED_SINCE variant='.$change['variant_id']);

                    continue;
                }

                // Saved through the model so storefront cache invalidation observers run.
                $variant->price_net_amount = $change[$to.'_net'];
                $variant->price_gross_amount = $change[$to.'_gross'];

                if ($changesVat) {
                    $variant->vat_rate = $change[$to.'_vat'] === null ? null : VatRate::from($change[$to.'_vat']);
                }
                $variant->save();
                $written++;
            }

            return $written;
        });
    }

    private function money(?int $minor): string
    {
        return $minor === null ? '(empty)' : number_format($minor / 100, 2, '.', '');
    }
}
