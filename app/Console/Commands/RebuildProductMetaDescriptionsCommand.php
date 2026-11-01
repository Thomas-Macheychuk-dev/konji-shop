<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Seo\ProductMetaDescription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RebuildProductMetaDescriptionsCommand extends Command
{
    protected $signature = 'seo:product-meta-descriptions
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore meta descriptions from a backup written by --write.}
        {--samples=20 : Number of sample changes to print.}';

    protected $description = 'Preview, apply or roll back rebuilding weak product meta descriptions from the product description.';

    private const BACKUP_DIRECTORY = 'seo-repairs';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $changes = [];
        $weak = 0;
        $noSource = [];

        Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->orderBy('id')
            ->select(['id', 'name', 'short_description', 'description', 'seo_description', 'external_source'])
            ->chunkById(200, function ($products) use (&$changes, &$weak, &$noSource): void {
                foreach ($products as $product) {
                    $old = $product->getRawOriginal('seo_description');

                    if (! ProductMetaDescription::isWeak($old)) {
                        continue;
                    }

                    $weak++;
                    $new = ProductMetaDescription::build($product->description, $product->short_description, (string) $product->name);

                    if ($new === null || $new === $old) {
                        $source = (string) ($product->external_source ?: 'unknown');
                        $noSource[$source] = ($noSource[$source] ?? 0) + 1;

                        continue;
                    }

                    $changes[] = [
                        'id' => $product->id,
                        'source' => $product->external_source,
                        'old_meta' => $old,
                        'new_meta' => $new,
                    ];
                }
            });

        arsort($noSource);

        $this->line('=== PRODUCT META DESCRIPTIONS ===');
        $this->line('WEAK_META='.$weak);
        $this->line('REBUILT_META='.count($changes));
        $this->line('NO_USABLE_DESCRIPTION='.array_sum($noSource));

        foreach (array_slice($noSource, 0, 10, true) as $source => $count) {
            $this->line('  NO_SOURCE '.$source.'='.$count);
        }

        $samples = max(0, (int) $this->option('samples'));
        $step = max(1, intdiv(count($changes), max(1, $samples)));

        for ($i = 0; $i < count($changes) && $i / $step < $samples; $i += $step) {
            $change = $changes[$i];
            $this->line($change['id'].' ['.$change['source'].']');
            $this->line('  OLD: '.($change['old_meta'] ?? '(empty)'));
            $this->line('  NEW: '.$change['new_meta']);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backupPath = self::BACKUP_DIRECTORY.'/product-meta-descriptions-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $written = $this->writeMeta($changes, 'old_meta', 'new_meta');

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN_META='.$written);
        $this->info('PRODUCT_META_WRITE=PASS');

        return self::SUCCESS;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $restored = $this->writeMeta($backup['changes'], 'new_meta', 'old_meta');

        $this->info('RESTORED_META='.$restored.' OF '.count($backup['changes']));
        $this->info('PRODUCT_META_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    /**
     * Write $to over each row whose current value still equals $from; rows edited since are left alone.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function writeMeta(array $changes, string $from, string $to): int
    {
        return DB::transaction(function () use ($changes, $from, $to): int {
            $written = 0;

            foreach ($changes as $change) {
                $product = Product::query()->withTrashed()->lockForUpdate()->find($change['id']);

                if ($product === null) {
                    throw new RuntimeException('Product disappeared: '.$change['id']);
                }

                if ($product->getRawOriginal('seo_description') !== $change[$from]) {
                    $this->warn('SKIPPED_CHANGED_SINCE='.$change['id']);

                    continue;
                }

                // Saved through the model so storefront cache invalidation observers run.
                $product->seo_description = $change[$to];
                $product->save();
                $written++;
            }

            return $written;
        });
    }
}
