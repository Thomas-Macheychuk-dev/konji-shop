<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductVariantStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Reh4Mat\Reh4MatSizeOptions;
use App\Services\Reh4Mat\Reh4MatSizeVariantSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gives Reh4Mat products one variant per size and side, read from the size table in their description.
 *
 * Reh4Mat products were imported with a single variant, so shoppers could not pick a size. Products whose
 * size table cannot be read with confidence are listed for review and left alone. Every write is backed up
 * so --rollback can remove the new variants and restore the old default ones.
 */
final class SyncReh4MatSizeVariantsCommand extends Command
{
    protected $signature = 'reh4mat:sync-size-variants
        {--write : Create the size/side variants after writing a private backup. Otherwise preview only.}
        {--rollback= : Remove the variants created by a --write backup and restore the previous ones.}';

    protected $description = 'Preview, apply or roll back size and side variants for Reh4Mat products.';

    public function handle(Reh4MatSizeVariantSync $sync): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $products = Product::query()
            ->where('external_source', 'reh4mat')
            ->with(['variants' => fn ($query) => $query->with('attributeValues')])
            ->orderBy('id')
            ->get();

        $toSplit = [];
        $counts = ['done' => 0, 'single' => 0, 'no_table' => 0, 'review' => 0, 'no_default' => 0];
        $lines = [];

        foreach ($products as $product) {
            $options = Reh4MatSizeOptions::fromDescription($product->description);
            $label = sprintf('product=%d [%s] %s', $product->id, $product->status?->value ?? '-', Str::limit((string) $product->name, 60));

            if ($options->status === Reh4MatSizeOptions::STATUS_REVIEW) {
                $counts['review']++;
                $lines[] = 'REVIEW '.$label.' flags='.implode(',', $options->flags).' unread='.implode(' | ', array_map(static fn (string $value): string => Str::limit($value, 40), $options->unreadLabels));

                continue;
            }

            if (! $options->needsVariants()) {
                $counts[$options->status === Reh4MatSizeOptions::STATUS_SINGLE ? 'single' : 'no_table']++;

                continue;
            }

            $plan = $sync->plan($product, $options);

            if ($this->alreadyDone($product, $plan)) {
                $counts['done']++;

                continue;
            }

            $template = $product->variants->firstWhere('external_variant_id', 'reh4mat-'.$product->external_id.'-default');

            if ($template === null) {
                $counts['no_default']++;
                $lines[] = 'NO_DEFAULT_VARIANT '.$label;

                continue;
            }

            $toSplit[] = [$product, $template, $options];
            $lines[] = sprintf(
                'SPLIT %s sizes=%s sides=%s variants=%d price=%s',
                $label,
                $options->sizes === [] ? '-' : implode(',', $options->sizes),
                $options->sided ? 'P,L' : '-',
                count($plan),
                $template->price_gross_amount === null ? 'none' : number_format($template->price_gross_amount / 100, 2, '.', ''),
            );
        }

        $this->line('=== REH4MAT SIZE VARIANTS ===');
        $this->line('PRODUCTS='.$products->count());
        $this->line('TO_SPLIT='.count($toSplit).' (VARIANTS_TO_CREATE='.array_sum(array_map(static fn (array $row): int => count($sync->plan($row[0], $row[2])), $toSplit)).')');
        $this->line('ALREADY_SPLIT='.$counts['done']);
        $this->line('SINGLE_OR_UNIVERSAL_SIZE='.$counts['single']);
        $this->line('NO_SIZE_TABLE='.$counts['no_table']);
        $this->line('NEEDS_REVIEW='.$counts['review']);
        $this->line('NO_DEFAULT_VARIANT='.$counts['no_default']);

        foreach ($lines as $line) {
            $this->line($line);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($toSplit === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backup = [];

        $created = DB::transaction(function () use ($toSplit, $sync, &$backup): int {
            $created = 0;

            foreach ($toSplit as [$product, $template, $options]) {
                $before = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->get(['id', 'status', 'is_default'])
                    ->map(static fn (ProductVariant $variant): array => [
                        'id' => $variant->id,
                        'status' => $variant->status->value,
                        'is_default' => (bool) $variant->is_default,
                    ])
                    ->all();

                $result = $sync->apply($product, $template, $options);
                $created += count($result['created_variant_ids']);

                $backup[] = [
                    'product_id' => $product->id,
                    'created_variant_ids' => $result['created_variant_ids'],
                    'variants_before' => array_values(array_filter(
                        $before,
                        static fn (array $row): bool => in_array($row['id'], $result['archived_variant_ids'], true),
                    )),
                ];
            }

            return $created;
        });

        $backupPath = 'catalogue-repairs/reh4mat-size-variants-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'products' => $backup,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->info('BACKUP='.$backupPath);
        $this->info('PRODUCTS_SPLIT='.count($toSplit));
        $this->info('VARIANTS_CREATED='.$created);
        $this->info('REH4MAT_SIZE_VARIANTS_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{external_variant_id: string, size: ?string, side: ?string}>  $plan
     */
    private function alreadyDone(Product $product, array $plan): bool
    {
        $live = $product->variants->filter(static fn (ProductVariant $variant): bool => $variant->status !== ProductVariantStatus::ARCHIVED);

        return $live->count() === count($plan)
            && $live->pluck('external_variant_id')->sort()->values()->all() === collect($plan)->pluck('external_variant_id')->sort()->values()->all();
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $products = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR)['products'];

        [$removed, $restored] = DB::transaction(function () use ($products): array {
            $removed = 0;
            $restored = 0;

            foreach ($products as $row) {
                // Saved through the model so storefront cache invalidation observers run.
                foreach (ProductVariant::query()->whereKey($row['created_variant_ids'])->get() as $variant) {
                    $variant->attributeValues()->detach();
                    $variant->delete();
                    $removed++;
                }

                foreach ($row['variants_before'] as $before) {
                    $variant = ProductVariant::query()->find($before['id']);

                    if ($variant === null) {
                        $this->warn('SKIPPED_MISSING variant='.$before['id']);

                        continue;
                    }

                    $variant->status = ProductVariantStatus::from($before['status']);
                    $variant->is_default = $before['is_default'];
                    $variant->save();
                    $restored++;
                }
            }

            return [$removed, $restored];
        });

        $this->info('VARIANTS_REMOVED='.$removed);
        $this->info('VARIANTS_RESTORED='.$restored);
        $this->info('REH4MAT_SIZE_VARIANTS_ROLLBACK=PASS');

        return self::SUCCESS;
    }
}
