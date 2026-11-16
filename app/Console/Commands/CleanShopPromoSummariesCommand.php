<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Seo\ShopPromoText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Replaces short descriptions that are a supplier shop's sales line ("Kup … za 21,69 zł | Darmowa dostawa od 119 zł - już w 24h")
 * with the first paragraph of the product's own description. The seni24 importer used seni24.pl's meta description as the
 * short description, so product pages showed seni24's old price and delivery terms under the title.
 */
final class CleanShopPromoSummariesCommand extends Command
{
    private const BACKUP_DIRECTORY = 'seo-repairs';

    private const SUMMARY_LIMIT = 500;

    protected $signature = 'catalogue:clean-shop-promo-summaries
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore short descriptions from a backup written by --write.}
        {--samples=10 : Number of sample changes to print.}';

    protected $description = 'Preview, apply or roll back replacing supplier shop sales lines in product short descriptions.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $changes = [];
        $perSource = [];

        Product::query()
            ->withTrashed()
            ->whereNotNull('short_description')
            ->select(['id', 'name', 'short_description', 'description', 'external_source'])
            ->chunkById(200, function ($products) use (&$changes, &$perSource): void {
                foreach ($products as $product) {
                    $old = $product->getRawOriginal('short_description');

                    if (! ShopPromoText::matches($old)) {
                        continue;
                    }

                    $source = (string) ($product->external_source ?: 'unknown');
                    $perSource[$source] = ($perSource[$source] ?? 0) + 1;

                    $changes[] = [
                        'id' => $product->id,
                        'source' => $source,
                        'name' => (string) $product->name,
                        'old_short' => $old,
                        'new_short' => self::summaryFrom($product->description),
                    ];
                }
            });

        arsort($perSource);
        $cleared = count(array_filter($changes, fn (array $change): bool => $change['new_short'] === null));

        $this->line('=== SHOP PROMO SHORT DESCRIPTIONS ===');
        $this->line('PROMO_SUMMARIES='.count($changes).' REPLACED_WITH_DESCRIPTION='.(count($changes) - $cleared).' CLEARED='.$cleared);

        foreach ($perSource as $source => $count) {
            $this->line('  SOURCE '.$source.'='.$count);
        }

        $samples = max(0, (int) $this->option('samples'));
        $step = max(1, intdiv(count($changes), max(1, $samples)));

        for ($i = 0; $i < count($changes) && $i / $step < $samples; $i += $step) {
            $change = $changes[$i];
            $this->line($change['id'].' ['.$change['source'].'] '.$change['name']);
            $this->line('  OLD: '.Str::limit(strip_tags((string) $change['old_short']), 160));
            $this->line('  NEW: '.($change['new_short'] === null ? '(empty)' : Str::limit(strip_tags($change['new_short']), 160)));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backupPath = self::BACKUP_DIRECTORY.'/shop-promo-summaries-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $written = $this->writeSummaries($changes, 'old_short', 'new_short');

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN_SUMMARIES='.$written);

        return self::SUCCESS;
    }

    /** The first paragraph of the description as plain text, or null when there is none or it is a sales line too. */
    public static function summaryFrom(?string $descriptionHtml): ?string
    {
        $html = (string) $descriptionHtml;
        $paragraph = preg_match('/<p\b[^>]*>(.*?)<\/p>/isu', $html, $matches) === 1 ? $matches[1] : $html;
        $text = Str::squish(html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($text === '' || ShopPromoText::matches($text)) {
            return null;
        }

        return '<p>'.e(Str::limit($text, self::SUMMARY_LIMIT, '…')).'</p>';
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $restored = $this->writeSummaries($backup['changes'], 'new_short', 'old_short');
        $this->info('RESTORED_SUMMARIES='.$restored.' OF '.count($backup['changes']));

        return self::SUCCESS;
    }

    /**
     * Write $to over each row whose current value still equals $from; rows edited since are left alone.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function writeSummaries(array $changes, string $from, string $to): int
    {
        return DB::transaction(function () use ($changes, $from, $to): int {
            $written = 0;

            foreach ($changes as $change) {
                $product = Product::query()->withTrashed()->lockForUpdate()->find($change['id']);

                if ($product === null) {
                    throw new RuntimeException('Product disappeared: '.$change['id']);
                }

                if ($product->getRawOriginal('short_description') !== $change[$from]) {
                    $this->warn('SKIPPED_CHANGED_SINCE='.$change['id']);

                    continue;
                }

                // Saved through the model so storefront cache invalidation observers run.
                $product->short_description = $change[$to];
                $product->save();
                $written++;
            }

            return $written;
        });
    }
}
