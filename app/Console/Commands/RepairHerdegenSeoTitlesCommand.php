<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class RepairHerdegenSeoTitlesCommand extends Command
{
    protected $signature = 'herdegen:repair-seo-titles
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore SEO titles from a backup written by --write.}
        {--samples=10 : Number of sample changes to print.}';

    protected $description = 'Preview, apply or roll back restoring Herdegen SEO titles that herdegen:enrich-drafts cut at 60 characters.';

    private const BACKUP_DIRECTORY = 'seo-repairs';

    /** herdegen:enrich-drafts used Str::limit($name.' | Herdegen', 60, ''). */
    private const CUT_LENGTH = 60;

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $changes = [];
        $checked = 0;

        Product::query()
            ->where('external_source', 'herdegen')
            ->orderBy('id')
            ->select(['id', 'name', 'seo_title'])
            ->chunkById(200, function ($products) use (&$changes, &$checked): void {
                foreach ($products as $product) {
                    $checked++;
                    $old = $product->getRawOriginal('seo_title');
                    $new = Str::squish(strip_tags((string) $product->name)).' | Herdegen';

                    // Only the exact truncation pattern; hand-edited titles never match it.
                    if (! is_string($old) || $old === $new || mb_strlen($old) !== self::CUT_LENGTH
                        || ! str_starts_with($new, $old)) {
                        continue;
                    }

                    $changes[] = ['id' => $product->id, 'old_title' => $old, 'new_title' => $new];
                }
            });

        $this->line('=== HERDEGEN SEO TITLES ===');
        $this->line('HERDEGEN_PRODUCTS='.$checked);
        $this->line('TRUNCATED_TITLES='.count($changes));

        foreach (array_slice($changes, 0, max(0, (int) $this->option('samples'))) as $change) {
            $this->line((string) $change['id']);
            $this->line('  OLD: '.$change['old_title']);
            $this->line('  NEW: '.$change['new_title']);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backupPath = self::BACKUP_DIRECTORY.'/herdegen-seo-titles-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $written = $this->writeTitles($changes, 'old_title', 'new_title');

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN_TITLES='.$written);
        $this->info('HERDEGEN_SEO_TITLES_WRITE=PASS');

        return self::SUCCESS;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $restored = $this->writeTitles($backup['changes'], 'new_title', 'old_title');

        $this->info('RESTORED_TITLES='.$restored.' OF '.count($backup['changes']));
        $this->info('HERDEGEN_SEO_TITLES_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    /**
     * Write $to over each row whose current value still equals $from; rows edited since are left alone.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function writeTitles(array $changes, string $from, string $to): int
    {
        return DB::transaction(function () use ($changes, $from, $to): int {
            $written = 0;

            foreach ($changes as $change) {
                $product = Product::query()->withTrashed()->lockForUpdate()->find($change['id']);

                if ($product === null) {
                    throw new RuntimeException('Product disappeared: '.$change['id']);
                }

                if ($product->getRawOriginal('seo_title') !== $change[$from]) {
                    $this->warn('SKIPPED_CHANGED_SINCE='.$change['id']);

                    continue;
                }

                // Saved through the model so storefront cache invalidation observers run.
                $product->seo_title = $change[$to];
                $product->save();
                $written++;
            }

            return $written;
        });
    }
}
