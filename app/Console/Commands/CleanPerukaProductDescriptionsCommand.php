<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Peruka\PerukaProductHtmlCleaner;
use Illuminate\Console\Command;

final class CleanPerukaProductDescriptionsCommand extends Command
{
    protected $signature = 'peruka:clean-descriptions
        {--dry-run : Show how many Peruka products would be changed without writing to the database.}
        {--limit= : Maximum number of Peruka products to inspect.}
        {--offset=0 : Number of Peruka products to skip before inspecting.}
        {--show-products : Print product IDs and fields that would be/were changed.}';

    protected $description = 'Remove HTML anchor tags from descriptions of already-imported Peruka products while keeping the link text/content.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $showProducts = (bool) $this->option('show-products');
        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');

        $query = Product::query()
            ->where('external_source', 'peruka')
            ->orderBy('id');

        $available = (clone $query)->count();

        $selectedQuery = clone $query;

        if ($limit !== null) {
            $selectedQuery->take($limit);
        } elseif ($offset > 0) {
            $selectedQuery->take(max(0, $available - $offset));
        }

        if ($offset > 0) {
            $selectedQuery->skip($offset);
        }

        $products = $selectedQuery->get();

        $this->info('Cleaning imported Peruka product descriptions.');
        $this->line('Available Peruka products: '.$available);
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.$products->count());
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database update'));

        if ($products->isEmpty()) {
            $this->warn('No Peruka products selected.');

            return self::SUCCESS;
        }

        $changedProducts = 0;
        $changedFields = 0;

        foreach ($products as $product) {
            $updates = [];
            $changedFieldNames = [];

            foreach (['description', 'short_description'] as $field) {
                $current = $product->{$field};

                if (! is_string($current) || trim($current) === '') {
                    continue;
                }

                $cleaned = PerukaProductHtmlCleaner::clean($current);

                if ($cleaned !== null && $cleaned !== $current) {
                    $updates[$field] = $cleaned;
                    $changedFieldNames[] = $field;
                }
            }

            if ($updates === []) {
                continue;
            }

            $changedProducts++;
            $changedFields += count($updates);

            if ($showProducts) {
                $this->line(sprintf(
                    'Product #%d %s: %s',
                    $product->id,
                    $product->name,
                    implode(', ', $changedFieldNames),
                ));
            }

            if (! $dryRun) {
                $product->forceFill($updates)->save();
            }
        }

        $this->info(($dryRun ? 'Products that would be updated: ' : 'Updated Peruka product descriptions: ').$changedProducts);
        $this->line('Changed fields: '.$changedFields);

        return self::SUCCESS;
    }

    private function nonNegativeIntOption(string $option, int $default): int
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function nullablePositiveIntOption(string $option): ?int
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
