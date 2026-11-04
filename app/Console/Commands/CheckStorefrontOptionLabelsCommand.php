<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attribute;
use Illuminate\Console\Command;

final class CheckStorefrontOptionLabelsCommand extends Command
{
    protected $signature = 'storefront:check-option-labels';

    protected $description = 'Read-only: compare config/storefront_labels.php with the attributes in the database.';

    public function handle(): int
    {
        $configuredSlugs = array_keys(config('storefront_labels.attributes', []));
        $existingSlugs = Attribute::query()->whereIn('slug', $configuredSlugs)->pluck('slug')->all();
        $missingSlugs = array_values(array_diff($configuredSlugs, $existingSlugs));

        $relabelled = Attribute::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Attribute $attribute): bool => $attribute->storefrontName() !== $attribute->name)
            ->map(fn (Attribute $attribute): array => [
                $attribute->id,
                $attribute->slug,
                $attribute->name,
                $attribute->storefrontName(),
            ])
            ->values()
            ->all();

        $this->info(count($relabelled).' attributes shown under a storefront label:');
        $this->table(['ID', 'Slug', 'Supplier name', 'Storefront label'], $relabelled);

        if ($missingSlugs === []) {
            $this->info('All configured attribute slugs exist.');

            return self::SUCCESS;
        }

        $this->warn('Configured slugs with no attribute in this database:');

        foreach ($missingSlugs as $slug) {
            $this->line('  '.$slug);
        }

        return self::FAILURE;
    }
}
