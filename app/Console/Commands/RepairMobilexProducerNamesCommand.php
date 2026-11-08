<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The Mobilex importer appends the producer to product names ("ABERDEEN MAN MED Scholl"). Before
 * November 2026 the scraper cut producer names at their first dot, so names were stored as
 * "ABERDEEN MAN MED Health and Fashion Shoes Italia S". This puts the producer the scraper now reads back.
 */
final class RepairMobilexProducerNamesCommand extends Command
{
    protected $signature = 'mobilex:repair-producer-names
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore names from a backup written by --write.}
        {--samples=10 : Number of sample changes to print.}';

    protected $description = 'Preview, apply or roll back replacing producer names the Mobilex scraper cut at their first dot in product names.';

    private const BACKUP_DIRECTORY = 'catalogue-repairs';

    /** Cut producer name => what the scraper reads now. */
    private const CUT_PRODUCERS = [
        'Health and Fashion Shoes Italia S' => 'Scholl',
        'Winncare Polska Sp' => 'Winncare Polska',
    ];

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $changes = [];
        $checked = 0;

        Product::query()
            ->withTrashed()
            ->where('external_source', 'mobilex')
            ->where(function ($query): void {
                foreach (array_keys(self::CUT_PRODUCERS) as $cut) {
                    $query->orWhere('name', 'like', '%'.$cut.'%');
                }
            })
            ->orderBy('id')
            ->select(['id', 'name'])
            ->chunkById(200, function ($products) use (&$changes, &$checked): void {
                foreach ($products as $product) {
                    $checked++;
                    $old = (string) $product->getRawOriginal('name');
                    $new = self::repairedName($old);

                    if ($new !== $old) {
                        $changes[] = ['id' => $product->id, 'old_name' => $old, 'new_name' => $new];
                    }
                }
            });

        $this->line('=== MOBILEX PRODUCER NAMES ===');
        $this->line('CANDIDATES='.$checked);
        $this->line('CUT_NAMES='.count($changes));

        foreach (array_slice($changes, 0, max(0, (int) $this->option('samples'))) as $change) {
            $this->line((string) $change['id']);
            $this->line('  OLD: '.$change['old_name']);
            $this->line('  NEW: '.$change['new_name']);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backupPath = self::BACKUP_DIRECTORY.'/mobilex-producer-names-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $written = $this->writeNames($changes, 'old_name', 'new_name');

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN_NAMES='.$written);
        $this->info('MOBILEX_PRODUCER_NAMES_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * Replaces a cut producer standing as a whole word ("Italia S", not "Italia S.p.A."); drops it
     * when the name already carries the producer it stands for.
     */
    public static function repairedName(string $name): string
    {
        foreach (self::CUT_PRODUCERS as $cut => $producer) {
            $pattern = '/\s*'.preg_quote($cut, '/').'(?![\p{L}\p{N}.])/u';

            if (preg_match($pattern, $name) !== 1) {
                continue;
            }

            $without = trim((string) preg_replace($pattern, '', $name));

            $name = mb_stripos($without, $producer) !== false
                ? $without
                : trim((string) preg_replace($pattern, '', (string) preg_replace($pattern, ' '.$producer, $name, 1)));
        }

        return $name;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $restored = $this->writeNames($backup['changes'], 'new_name', 'old_name');

        $this->info('RESTORED_NAMES='.$restored.' OF '.count($backup['changes']));
        $this->info('MOBILEX_PRODUCER_NAMES_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    /**
     * Write $to over each row whose current name still equals $from; rows edited since are left alone.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function writeNames(array $changes, string $from, string $to): int
    {
        return DB::transaction(function () use ($changes, $from, $to): int {
            $written = 0;

            foreach ($changes as $change) {
                $product = Product::query()->withTrashed()->lockForUpdate()->find($change['id']);

                if ($product === null) {
                    throw new RuntimeException('Product disappeared: '.$change['id']);
                }

                if ($product->getRawOriginal('name') !== $change[$from]) {
                    $this->warn('SKIPPED_CHANGED_SINCE='.$change['id']);

                    continue;
                }

                // Saved through the model so storefront cache invalidation observers run.
                $product->name = $change[$to];
                $product->save();
                $written++;
            }

            return $written;
        });
    }
}
