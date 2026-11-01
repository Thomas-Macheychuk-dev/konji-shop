<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Seo\SupplierTitleSuffix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class NormalizeSupplierSeoTitlesCommand extends Command
{
    protected $signature = 'seo:supplier-title-suffixes
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore titles from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back removal of supplier site branding from product SEO titles.';

    private const BACKUP_DIRECTORY = 'seo-repairs';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $changes = [];
        $rules = [];
        $unknown = [];

        Product::query()
            ->withTrashed()
            ->whereNotNull('seo_title')
            ->orderBy('id')
            ->select(['id', 'seo_title'])
            ->chunkById(1000, function ($products) use (&$changes, &$rules, &$unknown): void {
                foreach ($products as $product) {
                    $old = (string) $product->getRawOriginal('seo_title');
                    [$new, $rule] = SupplierTitleSuffix::apply($old);

                    if ($rule !== null) {
                        $changes[] = ['id' => $product->id, 'old_title' => $old, 'new_title' => $new, 'rule' => $rule];
                        $rules[$rule] = ($rules[$rule] ?? 0) + 1;
                    } elseif (preg_match('/\s[|]\s([^|]{3,})$/u', $old, $match) === 1) {
                        // Report remaining " | X" suffixes so new supplier branding can be reviewed.
                        $unknown[$match[1]] = ($unknown[$match[1]] ?? 0) + 1;
                    }
                }
            });

        arsort($unknown);
        ksort($rules);

        $this->line('=== SUPPLIER SEO TITLE SUFFIXES ===');
        $this->line('CHANGED_TITLES='.count($changes));

        foreach ($rules as $rule => $count) {
            $this->line('RULE_'.mb_strtoupper($rule).'='.$count);
        }

        foreach (array_slice($changes, 0, 15) as $change) {
            $this->line($change['id'].': '.$change['old_title'].' => '.$change['new_title']);
        }

        $this->line('UNCHANGED_PIPE_SUFFIXES (top 25, review for new rules):');

        foreach (array_slice($unknown, 0, 25, true) as $suffix => $count) {
            $this->line('  '.$count.' | '.$suffix);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backupPath = self::BACKUP_DIRECTORY.'/supplier-title-suffixes-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $written = $this->writeTitles($changes, 'old_title', 'new_title');

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN_TITLES='.$written);
        $this->info('SUPPLIER_SEO_TITLE_WRITE=PASS');

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
        $this->info('SUPPLIER_SEO_TITLE_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    /**
     * Write $to over each row whose current title still equals $from; rows edited since are left alone.
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
