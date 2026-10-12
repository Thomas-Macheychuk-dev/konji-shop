<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class QmedImageSynchronizer
{
    public function __construct(
        private readonly QmedImportPreflight $preflight,
        private readonly RemoteImageImporter $imageImporter,
    ) {}

    /** @param array<string,mixed> $dataset
     * @return array<string,mixed>
     */
    public function inspect(array $dataset, int $offset, int $limit): array
    {
        $base = $this->preflight->inspect($dataset, 156);
        $errors = $base['errors'] ?? [];
        if ($offset < 0 || $limit < 1 || $limit > 156 || $offset + $limit > 156) {
            $errors[] = 'Invalid batch boundaries.';
        }
        $selected = array_slice($dataset['products'] ?? [], max(0, $offset), max(0, $limit));
        $toImport = 0;
        $unchanged = 0;
        $references = 0;
        foreach ($selected as $index => $record) {
            if (! is_array($record)) {
                $errors[] = "Invalid batch record {$index}.";

                continue;
            }
            $id = $record['external_id'] ?? null;
            $matches = Product::withTrashed()->where('external_source', 'qmed')->where('external_id', $id)->get();
            if ($matches->count() !== 1) {
                $errors[] = "Missing or duplicate product {$id}.";

                continue;
            }
            $product = $matches->first();
            if ($product->trashed() || $product->status !== ProductStatus::DRAFT || $product->published_at !== null || $product->external_parent_sku !== null || $product->variants()->withTrashed()->exists()) {
                $errors[] = "Unsafe product {$id}.";

                continue;
            }
            $images = $record['images'] ?? null;
            if (! is_array($images) || ! array_is_list($images) || $images === []) {
                $errors[] = "Missing image manifest {$id}.";

                continue;
            }
            $seen = [];
            $main = 0;
            foreach ($images as $image) {
                $url = is_array($image) ? ($image['url'] ?? null) : null;
                $role = is_array($image) ? ($image['role'] ?? null) : null;
                try {
                    $url = is_string($url)
                        ? $this->normalizeQmedImageUrl($url)
                        : null;
                } catch (RuntimeException) {
                    $url = null;
                }
                $parts = is_string($url) ? parse_url($url) : false;
                if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'qmedinfo.pl' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ! str_starts_with($parts['path'] ?? '', '/images/') || str_contains($parts['path'] ?? '', '..') || ! in_array($role, ['main', 'gallery'], true) || isset($seen[$url])) {
                    $errors[] = "Invalid or duplicate image source for {$id}.";

                    continue;
                }
                $seen[$url] = true;
                $main += (int) ($role === 'main');
                $references++;
            }
            if ($main !== 1 || ($images[0]['role'] ?? null) !== 'main') {
                $errors[] = "Expected first image to be the sole main image for {$id}.";
            }
            $existing = $product->images()->get();
            if ($existing->isEmpty()) {
                $toImport++;

                continue;
            }
            // Validate imported rows as an ordered subset of the source
            // manifest. Duplicate-content references may be omitted.
            $rows = $existing->sortBy('sort_order')->values();
            $lastSourceIndex = -1;
            $seenHashes = [];

            if ($rows->isEmpty() || $rows->count() > count($images)) {
                $errors[] = "Invalid imported gallery size for {$id}.";

                continue;
            }

            foreach ($rows as $position => $row) {
                $expectedMain = $position === 0;

                if (
                    (int) $row->sort_order !== $position
                    || (bool) $row->is_main !== $expectedMain
                    || ! Storage::disk($row->disk)->exists($row->path)
                    || isset($seenHashes[$row->sha256])
                ) {
                    $errors[] = "Invalid imported image for {$id} at {$position}.";

                    continue;
                }

                $seenHashes[$row->sha256] = true;
                $matched = false;

                foreach ($images as $sourceIndex => $image) {
                    if ($sourceIndex <= $lastSourceIndex) {
                        continue;
                    }

                    if (
                        $row->source_url ===
                        $this->normalizeQmedImageUrl($image['url'])
                    ) {
                        $lastSourceIndex = $sourceIndex;
                        $matched = true;
                        break;
                    }
                }

                if (! $matched || ($position === 0 && $lastSourceIndex !== 0)) {
                    $errors[] = "Unexpected imported source for {$id} at {$position}.";
                }
            }

            $unchanged++;
        }

        return [
            'safe' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'selected' => count($selected), 'image_references' => $references,
            'products_to_import' => $toImport, 'unchanged' => $unchanged,
        ];
    }

    /** @param array<string,mixed> $dataset
     * @return array<string,int>
     */
    public function sync(array $dataset, int $offset, int $limit): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Qmed image writes restricted to local/testing.');
        }
        $check = $this->inspect($dataset, $offset, $limit);
        if (! $check['safe']) {
            throw new RuntimeException(implode(' | ', $check['errors']));
        }
        $created = 0;
        $unchanged = 0;
        $imagesCreated = 0;
        foreach (array_slice($dataset['products'], $offset, $limit) as $record) {
            $id = $record['external_id'];
            $product = Product::where('external_source', 'qmed')->where('external_id', $id)->firstOrFail();
            if ($product->images()->exists()) {
                $unchanged++;

                continue;
            }
            $rows = [];
            $hashes = [];
            foreach ($record['images'] as $order => $image) {
                // Files are content-addressed; a later database failure can leave
                // harmless orphan files, but never partially attached image rows.
                $download = $this->imageImporter->import(
                    $this->normalizeQmedImageUrl($image['url']),
                    'products/qmed/'.$id.'/gallery',
                    'public',
                    ['qmedinfo.pl'],
                    [],
                    ['verify_tls' => true],
                );
                if (isset($hashes[$download['sha256']])) {
                    // Same image content under a different manufacturer URL.
                    // Keep the first occurrence and omit this duplicate.
                    continue;
                }
                $hashes[$download['sha256']] = true;
                $rows[] = [
                    'disk' => $download['disk'], 'path' => $download['path'],
                    'source_url' => $this->normalizeQmedImageUrl($image['url']), 'mime_type' => $download['mime_type'],
                    'file_size' => $download['file_size'], 'sha256' => $download['sha256'],
                    'alt_text' => $product->name, 'title' => $product->name,
                    'sort_order' => count($rows), 'is_main' => count($rows) === 0,
                ];
            }
            DB::transaction(function () use ($product, $id, $rows): void {
                $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductStatus::DRAFT || $locked->published_at !== null || $locked->external_parent_sku !== null || $locked->variants()->withTrashed()->exists() || $locked->images()->exists()) {
                    throw new RuntimeException("Product modified while importing images: {$id}");
                }
                foreach ($rows as $row) {
                    $locked->images()->create($row);
                }
            });
            $created++;
            $imagesCreated += count($rows);
        }

        return ['created_products' => $created, 'unchanged_products' => $unchanged, 'created_images' => $imagesCreated];
    }

    private function normalizeQmedImageUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new RuntimeException('Invalid Qmed image URL.');
        }

        if (array_key_exists('fragment', $parts)) {
            $fragment = $parts['fragment'];

            if (! preg_match(
                '~^joomlaImage://local-images/[A-Za-z0-9_./%\~-]+\\?width=[0-9]+&height=[0-9]+$~D',
                $fragment,
            )) {
                throw new RuntimeException(
                    'Unexpected Qmed image URL fragment.'
                );
            }

            $url = explode('#', $url, 2)[0];
        }

        return $url;
    }
}
