<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\RemoteImageImporter;
use App\Services\Reh4Mat\Reh4MatProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * Adds gallery images to Reh4Mat products that have none, from the image URLs in the saved scrape.
 *
 * Reh4Mat moved its StabiloBed products to stabilobedsystem.pl; the old www.reh4mat.com/media/k2/ files
 * now answer 404 but are served unchanged under the same path there. Only images are added: products,
 * variants, categories and existing images are never changed or deleted.
 */
final class BackfillReh4MatImagesCommand extends Command
{
    protected $signature = 'reh4mat:backfill-images
        {--from=scrapers/reh4mat/full-product-data.json : Reh4Mat product-data JSON under storage/app.}
        {--image-limit=10 : Maximum number of images to add per product. Use 0 for no limit.}
        {--write : Download and attach the images, recording each added row in a private backup. Otherwise preview only, without downloads.}
        {--rollback= : Remove the images added by a backup written by --write.}';

    protected $description = 'Preview, apply or roll back adding scraped gallery images to Reh4Mat products without images.';

    private const BACKUP_DIRECTORY = 'catalogue-repairs';

    private const MOVED_K2_HOST = 'stabilobedsystem.pl';

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $scraped = $this->scrapedProducts((string) $this->option('from'));

        if ($scraped === null) {
            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('image-limit'));
        $plan = [];
        $notInScrape = [];
        $noImageUrls = [];
        $hosts = [];

        Product::query()
            ->where('external_source', 'reh4mat')
            ->whereDoesntHave('images')
            ->orderBy('id')
            ->each(function (Product $product) use ($scraped, $limit, &$plan, &$notInScrape, &$noImageUrls, &$hosts): void {
                $entry = $scraped[(string) $product->external_id] ?? null;

                if ($entry === null) {
                    $notInScrape[] = $product;

                    return;
                }

                $images = $this->candidateImages($entry, (string) $product->name, $limit);

                if ($images === []) {
                    $noImageUrls[] = [$product, $entry['canonical_url'] ?? $entry['source_url'] ?? null];

                    return;
                }

                foreach ($images as $image) {
                    $host = (string) parse_url($image['url'], PHP_URL_HOST);
                    $hosts[$host] = ($hosts[$host] ?? 0) + 1;
                }

                $plan[] = ['product' => $product, 'images' => $images];
            });

        arsort($hosts);

        $this->line('=== REH4MAT IMAGE BACKFILL ===');
        $this->line('PRODUCTS_WITHOUT_IMAGES='.(count($plan) + count($notInScrape) + count($noImageUrls)));
        $this->line('WITH_IMAGE_URLS='.count($plan));
        $this->line('IMAGE_URLS='.array_sum($hosts));
        $this->line('NO_IMAGE_URLS_IN_SCRAPE='.count($noImageUrls));
        $this->line('NOT_IN_SCRAPE='.count($notInScrape));

        foreach ($hosts as $host => $count) {
            $this->line('  HOST '.$host.'='.$count);
        }

        foreach (array_slice($plan, 0, 5) as $item) {
            $this->line($item['product']->id.' '.$item['product']->slug.' <- '.$item['images'][0]['url']);
        }

        foreach ($noImageUrls as [$product, $sourceUrl]) {
            $this->line('NO_IMAGE_URLS '.$product->id.' '.$product->slug.' <- '.($sourceUrl ?? '(no source url)'));
        }

        foreach ($notInScrape as $product) {
            $this->line('NOT_IN_SCRAPE '.$product->id.' '.$product->slug);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($plan === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backupPath = self::BACKUP_DIRECTORY.'/reh4mat-backfill-images-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $added = [];
        $this->writeBackup($backupPath, $added);

        $productsWithImages = 0;
        $failedUrls = 0;
        $skipped = 0;

        foreach ($plan as $item) {
            $product = $item['product'];
            $downloaded = [];

            foreach ($item['images'] as $image) {
                try {
                    $downloaded[] = $this->remoteImageImporter->import(
                        $image['url'],
                        'products/reh4mat/'.$product->external_id.'/gallery',
                        'public',
                        Reh4MatProductImporter::REMOTE_ASSET_ALLOWED_HOSTS,
                    ) + ['alt' => $image['alt']];
                } catch (Throwable $exception) {
                    $failedUrls++;
                    $this->warn('IMAGE_FAILED '.$product->id.' '.$image['url'].' — '.$exception->getMessage());
                }
            }

            if ($downloaded === []) {
                continue;
            }

            $rows = DB::transaction(function () use ($product, $downloaded): array {
                $locked = Product::query()->lockForUpdate()->find($product->id);

                // Images added since the preview (by hand or another run) win; nothing is merged into them.
                if ($locked === null || $locked->images()->exists()) {
                    return [];
                }

                $rows = [];

                foreach ($downloaded as $index => $image) {
                    if (in_array($image['path'], array_column($rows, 'path'), true)) {
                        continue;
                    }

                    // Created through the model so the storefront cache observer runs.
                    $row = ProductImage::query()->create([
                        'product_id' => $locked->id,
                        'disk' => $image['disk'],
                        'path' => $image['path'],
                        'source_url' => $image['source_url'],
                        'mime_type' => $image['mime_type'],
                        'file_size' => $image['file_size'],
                        'sha256' => $image['sha256'],
                        'alt_text' => $image['alt'],
                        'title' => $image['alt'],
                        'sort_order' => count($rows),
                        'is_main' => $rows === [],
                    ]);

                    $rows[] = ['id' => $row->id, 'product_id' => $locked->id, 'disk' => $row->disk, 'path' => $row->path];
                }

                return $rows;
            });

            if ($rows === []) {
                $skipped++;
                $this->warn('SKIPPED_HAS_IMAGES_NOW='.$product->id);

                continue;
            }

            array_push($added, ...$rows);
            $this->writeBackup($backupPath, $added);
            $productsWithImages++;
        }

        $this->info('BACKUP='.$backupPath);
        $this->info('PRODUCTS_GIVEN_IMAGES='.$productsWithImages.' OF '.count($plan));
        $this->info('IMAGES_ADDED='.count($added));
        $this->line('IMAGE_URLS_FAILED='.$failedUrls);
        $this->line('SKIPPED_HAS_IMAGES_NOW='.$skipped);
        $this->info('REH4MAT_IMAGE_BACKFILL=PASS');

        return self::SUCCESS;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $removed = 0;
        $filesDeleted = 0;

        foreach ($backup['added'] as $row) {
            $image = ProductImage::query()
                ->whereKey($row['id'])
                ->where('product_id', $row['product_id'])
                ->where('path', $row['path'])
                ->first();

            if ($image === null) {
                $this->warn('SKIPPED_CHANGED_SINCE='.$row['id']);

                continue;
            }

            $image->delete();
            $removed++;

            // Paths are content hashes; keep the file while any other row still shows it.
            if (! ProductImage::query()->where('disk', $row['disk'])->where('path', $row['path'])->exists()) {
                Storage::disk($row['disk'])->delete($row['path']);
                $filesDeleted++;
            }
        }

        $this->info('REMOVED_IMAGES='.$removed.' OF '.count($backup['added']));
        $this->info('FILES_DELETED='.$filesDeleted);
        $this->info('REH4MAT_IMAGE_BACKFILL_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array{url: string, alt: string}>
     */
    private function candidateImages(array $entry, string $productName, int $limit): array
    {
        $images = [];

        foreach ($entry['images'] ?? [] as $image) {
            $url = is_array($image) && is_string($image['url'] ?? null) ? $this->currentUrl(trim($image['url'])) : null;

            if ($url === null || $url === '' || isset($images[$url])) {
                continue;
            }

            $alt = is_string($image['alt'] ?? null) && trim($image['alt']) !== '' ? trim($image['alt']) : $productName;
            $images[$url] = ['url' => $url, 'alt' => $alt];
        }

        $images = array_values($images);

        return $limit > 0 ? array_slice($images, 0, $limit) : $images;
    }

    private function currentUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! in_array($host, ['reh4mat.com', 'www.reh4mat.com'], true) || ! str_starts_with($path, '/media/k2/')) {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return 'https://'.self::MOVED_K2_HOST.$path.(is_string($query) ? '?'.$query : '');
    }

    /**
     * Scraped products keyed the way Reh4MatProductImporter derives external_id.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function scrapedProducts(string $relativePath): ?array
    {
        $path = storage_path('app/'.ltrim($relativePath, '/'));

        if (! is_file($path)) {
            $this->error('SCRAPE_NOT_FOUND='.$path);

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('SCRAPE_INVALID_JSON='.$exception->getMessage());

            return null;
        }

        $products = [];

        foreach (is_array($decoded['products'] ?? null) ? $decoded['products'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = null;

            foreach (['external_product_id', 'external_id', 'sku', 'slug'] as $key) {
                if (is_string($entry[$key] ?? null) && trim($entry[$key]) !== '') {
                    $id = mb_substr(trim($entry[$key]), 0, 190);

                    break;
                }
            }

            $id ??= substr(sha1((string) ($entry['canonical_url'] ?? $entry['source_url'] ?? $entry['name'] ?? 'reh4mat-product')), 0, 32);
            $products[$id] ??= $entry;
        }

        $this->line('SCRAPE='.$path.' ('.count($products).' products, '.date('Y-m-d H:i', (int) filemtime($path)).')');

        return $products;
    }

    /**
     * @param  list<array<string, mixed>>  $added
     */
    private function writeBackup(string $backupPath, array $added): void
    {
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'added' => $added,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
