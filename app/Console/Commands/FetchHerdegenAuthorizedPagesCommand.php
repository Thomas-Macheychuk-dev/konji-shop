<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Fetches authorized retailer pages into private storage and optionally imports licensed images. */
final class FetchHerdegenAuthorizedPagesCommand extends Command
{
    protected $signature = 'herdegen:fetch-authorized-pages
        {--from=scrapers/herdegen/analysis/listing-snapshot.json}
        {--limit=5}
        {--delay-ms=500}
        {--fetch-pages : Save original product HTML privately}
        {--import-images : Download authorized images and link them to existing drafts}
        {--image-limit=5}';

    protected $description = 'Fetch authorized Herdegen product pages and images; never activate or price products.';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $limit = $this->numberOption('limit', 1, 46);
        $delay = $this->numberOption('delay-ms', 0, 60000);
        $imageLimit = $this->numberOption('image-limit', 0, 20);
        if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.json$~D', $from)
            || $limit === null || $delay === null || $imageLimit === null) {
            $this->error('HERDEGEN_FETCH=FAIL: invalid option.');
            return self::FAILURE;
        }
        $fetchPages = (bool) $this->option('fetch-pages');
        $importImages = (bool) $this->option('import-images');
        try {
            $disk = Storage::disk('local');
            $data = json_decode($disk->get($from), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['source'] ?? null) !== 'herdegen'
                || ($data['analysis_type'] ?? null) !== 'herdegen_offline_listing_snapshot'
                || !is_array($data['products'] ?? null) || !array_is_list($data['products'])
                || ($data['summary']['products'] ?? null) !== count($data['products'])
                || ($data['summary']['commercially_approved'] ?? null) !== 0) {
                throw new RuntimeException('Invalid source snapshot.');
            }
            $selected = array_slice($data['products'], 0, $limit);
            $products = [];
            $seen = [];
            foreach ($selected as $item) {
                $url = $item['source_url'] ?? null;
                $id = $item['external_id'] ?? null;
                if (!is_array($item) || !is_string($url) || !self::allowedUrl($url, false)
                    || !is_string($id) || $id !== substr(hash('sha256', $url), 0, 32)
                    || isset($seen[$id]) || ($item['brand'] ?? null) !== 'Herdegen'
                    || ($item['commercially_approved'] ?? null) !== false
                    || ($item['variant_candidates'] ?? null) !== []) {
                    throw new RuntimeException('Unsafe or duplicate product metadata.');
                }
                $seen[$id] = true;
                $product = Product::withTrashed()->where('external_source', 'herdegen')->where('external_id', $id)->first();
                if ($product === null || $product->trashed() || $product->status !== ProductStatus::DRAFT
                    || $product->published_at !== null || $product->variants()->exists()) {
                    throw new RuntimeException('Product not an eligible unpublished draft: '.$id);
                }
                $urls = $item['image_source_urls_unlicensed'] ?? [];
                if (!is_array($urls) || !array_is_list($urls)) {
                    throw new RuntimeException('Malformed image URLs.');
                }
                foreach (array_slice($urls, 0, $imageLimit) as $imageUrl) {
                    if (!is_string($imageUrl) || !self::allowedUrl($imageUrl, true)) {
                        throw new RuntimeException('Disallowed image URL in source metadata.');
                    }
                }
                $products[] = [$item, $product];
            }
            $pages = $images = $skipped = 0;
            $errors = [];
            foreach ($products as [$item, $product]) {
                $id = $item['external_id'];
                if ($fetchPages) {
                    try {
                        $path = 'scrapers/herdegen/pages/'.$id.'.html';
                        if ($disk->exists($path)) {
                            $skipped++;
                        } else {
                            $body = $this->download($item['source_url'], 'text/html', 2_000_000, $delay);
                            if (!str_contains(strtolower(substr($body, 0, 4096)), '<html')
                                && !str_contains(strtolower(substr($body, 0, 4096)), '<!doctype')) {
                                throw new RuntimeException('Response is not HTML.');
                            }
                            if (!$disk->put($path, $body)) {
                                throw new RuntimeException('Unable to save HTML.');
                            }
                            $pages++;
                        }
                    } catch (Throwable $e) {
                        $errors[] = $id.': page: '.$e->getMessage();
                    }
                }
                if ($importImages) {
                    foreach (array_slice($item['image_source_urls_unlicensed'], 0, $imageLimit) as $index => $url) {
                        try {
                            if (ProductImage::query()->where('product_id', $product->id)->where('source_url', $url)->exists()) {
                                $skipped++;
                                continue;
                            }
                            $bytes = $this->download($url, 'image/*', 5_000_000, $delay);
                            $imageInfo = @getimagesizefromstring($bytes);
                            $mime = $imageInfo['mime'] ?? null;
                            $extension = match ($mime) {
                                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null,
                            };
                            if ($extension === null || ($imageInfo[0] ?? 0) < 1 || ($imageInfo[1] ?? 0) < 1
                                || ($imageInfo[0] ?? 0) > 10000 || ($imageInfo[1] ?? 0) > 10000) {
                                throw new RuntimeException('Unsupported or oversized image dimensions/type.');
                            }
                            $sha = hash('sha256', $bytes);
                            $path = 'products/herdegen/'.$id.'/'.$sha.'.'.$extension;
                            $public = Storage::disk('public');
                            if (!$public->exists($path) && !$public->put($path, $bytes)) {
                                throw new RuntimeException('Image storage failed.');
                            }
                            // A failed DB insert leaves an unreferenced object, never a published product.
                            ProductImage::query()->firstOrCreate(
                                ['product_id' => $product->id, 'source_url' => $url],
                                ['disk' => 'public', 'path' => $path, 'mime_type' => $mime,
                                    'file_size' => strlen($bytes), 'sha256' => $sha,
                                    'alt_text' => $product->name, 'sort_order' => $index,
                                    'is_main' => !$product->images()->exists()]
                            );
                            $images++;
                        } catch (Throwable $e) {
                            $errors[] = $id.': image: '.$e->getMessage();
                        }
                    }
                }
            }
            $this->line('SELECTED='.count($products));
            $this->line('MODE='.($fetchPages || $importImages ? 'AUTHORIZED_FETCH' : 'DRY_RUN'));
            $this->line('HTML_SAVED='.$pages);
            $this->line('IMAGES_IMPORTED='.$images);
            $this->line('ALREADY_PRESENT='.$skipped);
            $this->line('FAILURES='.count($errors));
            $this->line('PRICES_IMPORTED=0');
            $this->line('VARIANTS_CREATED=0');
            foreach ($errors as $error) {
                $this->warn($error);
            }
            if ($errors !== []) {
                $this->error('HERDEGEN_FETCH=FAIL');
                return self::FAILURE;
            }
            $this->info('HERDEGEN_FETCH=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN_FETCH=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function numberOption(string $key, int $min, int $max): ?int
    {
        $value = (string) $this->option($key);
        return ctype_digit($value) && (int) $value >= $min && (int) $value <= $max ? (int) $value : null;
    }

    public static function allowedUrl(string $url, bool $image): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'www.orteo.pl'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $path = $parts['path'] ?? '';

        if ($image) {
            if (!preg_match(
                '~^/img/product_media/(?:[A-Za-z0-9_-]+/)+[A-Za-z0-9_().-]+\.(?:jpg|jpeg|png|webp)$~iD',
                $path
            )) {
                return false;
            }

            // Reject path traversal and dot segments.
            foreach (explode('/', $path) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return false;
                }
            }

            return true;
        }

        return preg_match('~^/[A-Za-z0-9_-]+$~D', $path) === 1;
    }

    private function download(string $url, string $accept, int $maxBytes, int $delayMs): string
    {
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
        $response = Http::withOptions(['allow_redirects' => false, 'verify' => true])
            ->withHeaders(['Accept' => $accept, 'User-Agent' => 'KonjiShopAuthorizedCatalogImporter/1.0'])
            ->timeout(20)->get($url);
        if (!$response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }
        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('Empty or oversized content.');
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($accept === 'text/html' && !in_array($type, ['text/html', 'application/xhtml+xml'], true)) {
            throw new RuntimeException('Unexpected HTML content type.');
        }
        if ($accept === 'image/*' && !str_starts_with($type, 'image/')) {
            throw new RuntimeException('Unexpected image content type.');
        }
        return $bytes;
    }
}
