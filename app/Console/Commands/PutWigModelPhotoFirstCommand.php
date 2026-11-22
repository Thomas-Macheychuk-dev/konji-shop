<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Peruka\PerukaPhotoOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Shows the hairstyle, not a close-up of the hair colour, as the first photo of peruka.pl wigs (owner's
 * feedback, 16 November 2026). See PerukaPhotoOrder. The colour sample stays in the gallery after the model
 * photo; no photo is deleted. A default image the admin chose is cleared only when it is a colour sample.
 */
final class PutWigModelPhotoFirstCommand extends Command
{
    protected $signature = 'peruka:model-photo-first
        {--samples=30 : How many changes and unresolved products to list.}
        {--write : Reorder the photos after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the photo order from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back putting the wig model photo before the colour sample.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $samples = max(0, (int) $this->option('samples'));
        $changes = [];
        $noModelPhoto = [];
        $products = 0;

        Product::query()
            ->where('external_source', 'peruka')
            ->with('images')
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$changes, &$noModelPhoto, &$products): void {
                foreach ($chunk as $product) {
                    $products++;
                    $images = $this->displayOrder($product);

                    if ($images === []) {
                        continue;
                    }

                    $urls = array_map(fn (ProductImage $image): ?string => $image->source_url, $images);
                    $order = PerukaPhotoOrder::order($urls);

                    if ($order[0] !== 0) {
                        $changes[] = $this->change($product, $images, $order);
                    } elseif (PerukaPhotoOrder::isColourSample($urls[0])) {
                        $noModelPhoto[] = ['id' => $product->id, 'slug' => $product->slug, 'files' => array_map(PerukaPhotoOrder::fileName(...), $urls)];
                    }
                }
            });

        $this->line('=== WIG MODEL PHOTO FIRST ===');
        $this->line(sprintf('PRODUCTS=%d CHANGES=%d NO_MODEL_PHOTO=%d', $products, count($changes), count($noModelPhoto)));

        foreach (array_slice($changes, 0, $samples) as $change) {
            $this->line(sprintf('MOVE product=%d %s: %s -> %s', $change['id'], $change['slug'], $change['old_first'], $change['new_first']));
        }

        foreach (array_slice($noModelPhoto, 0, $samples) as $product) {
            $this->line(sprintf('NO_MODEL_PHOTO product=%d %s: %s', $product['id'], $product['slug'], implode(', ', $product['files'])));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            return self::SUCCESS;
        }

        $backupPath = 'catalogue-repairs/wig-model-photo-first-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->info('BACKUP='.$backupPath);
        $this->info('REORDERED='.$this->apply($changes, 'old', 'new'));

        return self::SUCCESS;
    }

    /**
     * The photos in the order the product page shows them: the chosen default image, then the main image,
     * then by position.
     *
     * @return list<ProductImage>
     */
    private function displayOrder(Product $product): array
    {
        $defaultId = $product->default_image_type === Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE ? $product->default_image_id : null;

        return $product->images
            ->sortBy(fn (ProductImage $image): array => [$image->id === $defaultId ? 0 : 1, $image->is_main ? 0 : 1, $image->sort_order, $image->id])
            ->values()
            ->all();
    }

    /**
     * @param  list<ProductImage>  $images
     * @param  list<int>  $order
     * @return array<string, mixed>
     */
    private function change(Product $product, array $images, array $order): array
    {
        $old = [];
        $new = [];

        foreach ($images as $image) {
            $old[$image->id] = ['sort_order' => (int) $image->sort_order, 'is_main' => (bool) $image->is_main];
        }

        foreach ($order as $position => $index) {
            $new[$images[$index]->id] = ['sort_order' => $position, 'is_main' => $position === 0];
        }

        $defaultIsSample = $product->default_image_type === Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE
            && $product->default_image_id === $images[0]->id;

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'old_first' => PerukaPhotoOrder::fileName($images[0]->source_url),
            'new_first' => PerukaPhotoOrder::fileName($images[$order[0]]->source_url),
            'old' => ['default_image_type' => $product->default_image_type, 'default_image_id' => $product->default_image_id, 'images' => $old],
            'new' => $defaultIsSample
                ? ['default_image_type' => null, 'default_image_id' => null, 'images' => $new]
                : ['default_image_type' => $product->default_image_type, 'default_image_id' => $product->default_image_id, 'images' => $new],
        ];
    }

    /** @param  list<array<string, mixed>>  $changes */
    private function apply(array $changes, string $from, string $to): int
    {
        $written = 0;

        foreach ($changes as $change) {
            $product = Product::query()->with('images')->find($change['id']);

            if ($product === null || ! $this->matches($product, $change[$from])) {
                $this->warn('SKIPPED_CHANGED_SINCE product='.$change['id']);

                continue;
            }

            DB::transaction(function () use ($product, $change, $to): void {
                // Saved through the models so storefront cache invalidation observers run.
                foreach ($product->images as $image) {
                    $image->sort_order = $change[$to]['images'][$image->id]['sort_order'];
                    $image->is_main = $change[$to]['images'][$image->id]['is_main'];
                    $image->save();
                }

                $product->default_image_type = $change[$to]['default_image_type'];
                $product->default_image_id = $change[$to]['default_image_id'];
                $product->save();
            });

            $written++;
        }

        return $written;
    }

    /** @param  array<string, mixed>  $state */
    private function matches(Product $product, array $state): bool
    {
        if ($product->default_image_type !== $state['default_image_type'] || $product->default_image_id !== $state['default_image_id']) {
            return false;
        }

        $images = $product->images->keyBy('id');

        if ($images->count() !== count($state['images'])) {
            return false;
        }

        foreach ($state['images'] as $id => $expected) {
            $image = $images->get((int) $id);

            if ($image === null || (int) $image->sort_order !== $expected['sort_order'] || (bool) $image->is_main !== $expected['is_main']) {
                return false;
            }
        }

        return true;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $this->info('RESTORED='.$this->apply($backup['changes'], 'new', 'old').' OF '.count($backup['changes']));

        return self::SUCCESS;
    }
}
