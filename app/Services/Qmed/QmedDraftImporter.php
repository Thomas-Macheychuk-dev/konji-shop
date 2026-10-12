<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates immutable, non-purchasable Qmed catalogue drafts.
 * Does not create categories, variants, prices, images or download documents.
 */
final class QmedDraftImporter
{
    public function __construct(private readonly QmedImportPreflight $preflight) {}

    /** @param array<string,mixed> $dataset
     * @return array{selected:int,created:int,unchanged:int,database_writes:int}
     */
    public function import(array $dataset, int $expectedCount, int $limit, int $offset = 0): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new RuntimeException('Limit must be positive and offset nonnegative.');
        }

        return DB::transaction(function () use ($dataset, $expectedCount, $limit, $offset): array {
            // Revalidate within the database transaction, not just in the CLI caller.
            $gate = $this->preflight->inspect($dataset, $expectedCount);
            if (($gate['safe'] ?? false) !== true) {
                throw new RuntimeException('Qmed preflight failed: '.implode(' | ', array_slice($gate['errors'] ?? [], 0, 10)));
            }

            $selected = array_slice($dataset['products'], $offset, $limit);
            if ($selected === []) {
                throw new RuntimeException('No Qmed records selected.');
            }

            $created = 0;
            $unchanged = 0;

            foreach ($selected as $source) {
                $externalId = (string) $source['external_id'];
                $canonical = (string) $source['source_url'];
                $slug = 'qmed-'.substr(hash('sha256', $canonical), 0, 40);
                $name = trim((string) $source['name']);
                $description = (string) $source['description_html'];

                // No silent slug reassignment or overwriting previously hand-edited records.
                $bySlug = Product::withTrashed()->where('slug', $slug)->lockForUpdate()->first();
                $matches = Product::withTrashed()->where('external_source', 'qmed')
                    ->where('external_id', $externalId)->lockForUpdate()->get();
                if ($matches->count() > 1) {
                    throw new RuntimeException('Duplicate database Qmed identity: '.$externalId);
                }

                $existing = $matches->first();
                if ($bySlug !== null && ($existing === null || $bySlug->id !== $existing->id)) {
                    throw new RuntimeException('Slug collision for '.$externalId);
                }

                if ($existing !== null) {
                    if ($existing->trashed()
                        || $existing->status !== ProductStatus::DRAFT
                        || $existing->published_at !== null
                        || $existing->external_parent_sku !== null
                        || $existing->variants()->withTrashed()->exists()
                        || $existing->images()->exists()
                        || $existing->categories()->exists()
                        || $existing->slug !== $slug
                        || $existing->name !== $name
                        || $existing->description !== $description) {
                        throw new RuntimeException('Existing Qmed draft differs or was enriched: '.$externalId);
                    }
                    $unchanged++;

                    continue;
                }

                Product::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'short_description' => null,
                    'description' => $description,
                    'status' => ProductStatus::DRAFT,
                    'published_at' => null,
                    'external_source' => 'qmed',
                    'external_id' => $externalId,
                    'external_parent_sku' => null,
                ]);
                $created++;
            }

            return [
                'selected' => count($selected),
                'created' => $created,
                'unchanged' => $unchanged,
                'database_writes' => $created,
            ];
        }, 1);
    }
}
