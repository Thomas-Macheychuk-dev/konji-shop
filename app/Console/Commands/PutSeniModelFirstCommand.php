<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves the Seni product line to the front of Seni product names, so product lists show which product it
 * is before the name gets cut off (owner's feedback, 15 November 2026):
 * "Pieluchomajtki dla dorosłych zapinane na rzepy Seni Standard Plus Air"
 * becomes "Seni Standard Plus Air pieluchomajtki dla dorosłych zapinane na rzepy".
 * The line is "Seni" and the capitalised words after it; slugs stay the same, so product URLs do not change.
 */
final class PutSeniModelFirstCommand extends Command
{
    protected $signature = 'seni:model-first-names
        {--write : Rename the products after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the names from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back putting the Seni product line first in Seni product names.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $renames = [];

        Product::query()
            ->where('name', 'like', '%Seni %')
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$renames): void {
                foreach ($products as $product) {
                    $name = self::modelFirst((string) $product->name);

                    if ($name !== null && $name !== $product->name) {
                        $renames[] = ['id' => $product->id, 'old' => $product->name, 'new' => $name];
                    }
                }
            });

        $this->line('=== SENI NAMES ===');
        $this->line('RENAMES='.count($renames));

        foreach ($renames as $rename) {
            $this->line(sprintf('RENAME product=%d "%s" -> "%s"', $rename['id'], $rename['old'], $rename['new']));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($renames === []) {
            return self::SUCCESS;
        }

        $backupPath = 'catalogue-repairs/seni-names-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'renames' => $renames,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $written = $this->apply($renames, 'old', 'new');

        $this->info('BACKUP='.$backupPath);
        $this->info('RENAMED='.$written);

        return self::SUCCESS;
    }

    /**
     * The name with the Seni line first, or null when the name does not have one after other words.
     * "Super Seni ..." is a product line of its own and stays as it is.
     */
    public static function modelFirst(string $name): ?string
    {
        if (! preg_match('/^(?<before>.+?)\s+(?<line>Seni(?:\s+(?:\p{Lu}[\p{L}\-+]*|\d+(?=\s+\p{Lu})|for(?=\s+\p{Lu})))*)(?<after>(?:\s.*)?)$/u', $name, $match)) {
            return null;
        }

        $before = trim($match['before']);

        if ($before === '' || preg_match('/^Super$/u', $before) || mb_strtolower($match['line']) === 'seni') {
            return null;
        }

        $words = trim(mb_strtolower(mb_substr($before, 0, 1)).mb_substr($before, 1).' '.trim($match['after']));

        return $match['line'].' '.$words;
    }

    /** @param  list<array{id: int, old: string, new: string}>  $renames */
    private function apply(array $renames, string $from, string $to): int
    {
        $written = 0;

        foreach ($renames as $rename) {
            $product = Product::query()->find($rename['id']);

            if ($product === null || $product->name !== $rename[$from]) {
                $this->warn('SKIPPED_CHANGED_SINCE product='.$rename['id']);

                continue;
            }

            // Saved through the model so storefront cache invalidation observers run.
            $product->name = $rename[$to];
            $product->save();
            $written++;
        }

        return $written;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $this->info('RESTORED='.$this->apply($backup['renames'], 'new', 'old').' OF '.count($backup['renames']));

        return self::SUCCESS;
    }
}
