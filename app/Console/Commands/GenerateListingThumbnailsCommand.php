<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Services\Images\ListingThumbnail;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Makes the small listing copy of each active product's card image (the image product cards show).
 *
 * Originals are up to ~800 KB; cards need at most 600px. Images already that small use themselves as
 * their thumbnail. Additive and resumable: --limit bounds one run, a scheduled hourly run picks up new
 * imports, and cards keep the full image until a thumbnail exists.
 */
final class GenerateListingThumbnailsCommand extends Command
{
    protected $signature = 'catalogue:generate-thumbnails
        {--write : Create the thumbnails. Otherwise only count what is missing.}
        {--limit=1000 : Most images to process in this run.}';

    protected $description = 'Preview or create the small listing copies of product card images.';

    public function handle(ListingThumbnail $thumbnails, StorefrontCache $cache): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $missing = 0;
        $queue = [];

        Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->with(['images', 'attributeValueImages'])
            ->select(['id', 'default_image_type', 'default_image_id'])
            ->chunkById(500, function ($products) use (&$missing, &$queue, $limit): void {
                foreach ($products as $product) {
                    $image = $product->selectedDefaultImage();

                    if ($image === null || filled($image->thumbnail_path)) {
                        continue;
                    }

                    $missing++;

                    if (count($queue) < $limit) {
                        $queue[] = $image;
                    }
                }
            });

        $this->line('=== LISTING THUMBNAILS ===');
        $this->line('MISSING='.$missing.' THIS_RUN='.count($queue));

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        $created = 0;
        $alreadySmall = 0;
        $failed = 0;

        foreach ($queue as $image) {
            /** @var ProductImage|ProductAttributeValueImage $image */
            try {
                $disk = Storage::disk($image->disk);
                $jpeg = $thumbnails->make((string) $disk->get($image->path));

                if ($jpeg === null) {
                    $image->forceFill(['thumbnail_path' => $image->path])->saveQuietly();
                    $alreadySmall++;

                    continue;
                }

                $path = ListingThumbnail::pathFor($image->path);
                $disk->put($path, $jpeg, ['visibility' => 'public', 'ContentType' => 'image/jpeg']);
                $image->forceFill(['thumbnail_path' => $path])->saveQuietly();
                $created++;
            } catch (\Throwable $exception) {
                // The card keeps the full image; recording it stops the hourly run retrying it forever.
                $image->forceFill(['thumbnail_path' => $image->path])->saveQuietly();
                $failed++;
                $this->warn(sprintf('FAILED %s=%d path=%s %s', class_basename($image), $image->id, $image->path, $exception->getMessage()));
            }
        }

        if ($queue !== []) {
            // Quiet saves skip the model observers; cached listings must pick up the new image URLs.
            $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        }

        $this->info('CREATED='.$created.' ALREADY_SMALL='.$alreadySmall.' FAILED='.$failed.' STILL_MISSING='.max(0, $missing - count($queue)));
        $this->info('LISTING_THUMBNAILS_WRITE=PASS');

        return self::SUCCESS;
    }
}
