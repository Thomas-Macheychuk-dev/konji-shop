<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use App\Enums\ProductStatus;
use App\Models\Product;

final class QmedImportPreflight
{
    /** @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function inspect(array $dataset, int $expectedCount = 156): array
    {
        $errors = [];
        $products = $dataset['products'] ?? null;
        if (($dataset['source'] ?? null) !== 'qmed' || ! is_array($products) || ! array_is_list($products)) {
            return ['safe' => false, 'errors' => ['Invalid Qmed dataset envelope.'], 'products' => 0];
        }
        if (count($products) !== $expectedCount) {
            $errors[] = "Expected {$expectedCount} records, found ".count($products).'.';
        }
        if (($dataset['failed_urls'] ?? []) !== []) {
            $errors[] = 'Dataset contains failed URLs.';
        }

        $ids = [];
        $urls = [];
        $slugs = [];
        $validIds = [];
        foreach ($products as $index => $product) {
            $label = "Record {$index}";
            if (! is_array($product)) {
                $errors[] = "{$label}: not an object.";

                continue;
            }
            $url = $product['source_url'] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            $path = is_array($parts) ? ($parts['path'] ?? '') : '';
            $canonical = is_array($parts)
                && ($parts['scheme'] ?? '') === 'https'
                && strtolower($parts['host'] ?? '') === 'qmedinfo.pl'
                && ! isset($parts['port']) && ! isset($parts['user']) && ! isset($parts['pass'])
                && ! isset($parts['query']) && ! isset($parts['fragment'])
                && preg_match('~^/pl/oferta/(?:[^/]+/)+[^/]+$~', $path) === 1
                && ! str_contains($path, '..')
                ? 'https://qmedinfo.pl'.rtrim($path, '/')
                : null;
            if ($canonical === null) {
                $errors[] = "{$label}: invalid canonical source URL.";

                continue;
            }
            $expectedId = 'qmed-'.hash('sha256', $canonical);
            if (($product['external_id'] ?? null) !== $expectedId || isset($ids[$expectedId])) {
                $errors[] = "{$label}: missing, inconsistent or duplicate external ID.";
            }
            $ids[$expectedId] = true;
            $validIds[] = $expectedId;
            if (isset($urls[$canonical])) {
                $errors[] = "{$label}: duplicate canonical URL.";
            }
            $urls[$canonical] = true;
            $slug = 'qmed-'.substr(hash('sha256', $canonical), 0, 40);
            if (isset($slugs[$slug])) {
                $errors[] = "{$label}: slug collision.";
            }
            $slugs[$slug] = true;
            if (($product['source'] ?? null) !== 'qmed'
                || ! is_string($product['name'] ?? null) || trim($product['name']) === ''
                || ! is_string($product['description_html'] ?? null) || trim($product['description_html']) === ''
                || ($product['status'] ?? null) !== 'draft'
                || ($product['sku'] ?? null) !== null
                || ($product['price_gross_amount'] ?? null) !== null
                || ($product['variant_candidates'] ?? null) !== []
                || ($product['variants_unresolved'] ?? null) !== true) {
                $errors[] = "{$label}: unsafe or incomplete draft product contract.";
            }
            if (! is_array($product['categories'] ?? null) || ($product['categories'] ?? []) === []) {
                $errors[] = "{$label}: missing manufacturer categories.";
            }
            if (! is_array($product['technical_tables'] ?? null)
                || ! is_array($product['attachments'] ?? null)
                || ! is_array($product['images'] ?? null) || $product['images'] === []) {
                $errors[] = "{$label}: missing structured collections or images.";
            }
            foreach (($product['images'] ?? []) as $image) {
                $media = is_array($image) ? ($image['url'] ?? null) : null;
                $host = is_string($media) ? parse_url($media, PHP_URL_HOST) : null;
                $scheme = is_string($media) ? parse_url($media, PHP_URL_SCHEME) : null;
                if ($host !== 'qmedinfo.pl' || $scheme !== 'https') {
                    $errors[] = "{$label}: unsafe image URL.";
                }
            }
            foreach (($product['attachments'] ?? []) as $attachment) {
                if (! is_array($attachment)
                    || ($attachment['downloaded'] ?? null) !== false
                    || (! array_key_exists('download_url', $attachment)
                    || $attachment['download_url'] !== null)
                    || ($attachment['request_fields']['option'] ?? null) !== 'com_ajax'
                    || ($attachment['request_fields']['plugin'] ?? null) !== 'jmpattachments'
                    || ! ctype_digit((string) ($attachment['request_fields']['id'] ?? ''))) {
                    $errors[] = "{$label}: unverified attachment metadata.";
                }
            }
        }

        $existing = Product::withTrashed()->where('external_source', 'qmed')->get();
        $existingById = $existing->groupBy('external_id');
        foreach ($existingById as $id => $matches) {
            if ($matches->count() !== 1 || ! isset($ids[$id])) {
                $errors[] = "Database: unexpected or duplicate Qmed identity {$id}.";

                continue;
            }
            $record = $matches->first();
            if ($record->trashed() || $record->status !== ProductStatus::DRAFT
                || $record->published_at !== null || $record->variants()->withTrashed()->exists()) {
                $errors[] = "Database: Qmed record {$id} has been deleted, published or manually enriched.";
            }
            if ($record->external_parent_sku !== null) {
                $errors[] = "Database: Qmed record {$id} has a manufacturer SKU.";
            }
        }
        foreach (array_keys($slugs) as $slug) {
            $collision = Product::withTrashed()->where('slug', $slug)->first();
            if ($collision !== null && ($collision->external_source !== 'qmed'
                || ! isset($ids[$collision->external_id]))) {
                $errors[] = "Database: foreign slug collision {$slug}.";
            }
        }

        return [
            'safe' => $errors === [],
            'source' => 'qmed',
            'products' => count($products),
            'unique_external_ids' => count($ids),
            'existing_qmed_records' => $existing->count(),
            'planned_creates' => count(array_diff(array_keys($ids), $existingById->keys()->all())),
            'planned_updates' => count(array_intersect(array_keys($ids), $existingById->keys()->all())),
            'database_writes' => false,
            'errors' => array_values(array_unique($errors)),
        ];
    }
}
