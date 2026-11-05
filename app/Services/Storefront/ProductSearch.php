<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Storefront product search: every word must appear in the name, descriptions or a variant SKU, and
 * name matches rank first. When nothing matches, suggest the closest words from product names.
 */
final class ProductSearch
{
    private const MAX_TERMS = 6;

    private const MAX_RESULTS = 3000;

    private const IDS_TTL_SECONDS = 600;

    public function __construct(
        private readonly StorefrontCache $cache,
    ) {}

    /**
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $words = preg_split('/[\s,;]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) >= 2,
        ))), 0, self::MAX_TERMS);
    }

    /**
     * Limit a listing to the products matching the search. The slow text match over descriptions
     * runs once per query and is cached, so the page, total and brand list reuse it.
     */
    public function restrict(Builder $listing, string $search): Builder
    {
        $terms = $this->terms($search);

        $ids = $terms === [] ? [] : $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_CATALOGUE,
            'search.ids.v1.'.sha1(implode(' ', $terms)),
            fn (): array => $this->apply(
                Product::query()->where('products.status', ProductStatus::ACTIVE->value)->whereNotNull('products.slug'),
                $search,
            )->limit(self::MAX_RESULTS)->pluck('products.id')->map(static fn ($id): int => (int) $id)->all(),
            self::IDS_TTL_SECONDS,
        );

        return $ids === [] ? $listing->whereRaw('1 = 0') : $listing->whereIn('products.id', $ids);
    }

    public function apply(Builder $query, string $search): Builder
    {
        $terms = $this->terms($search);

        if ($terms === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($terms as $term) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->where(function (Builder $match) use ($like): void {
                $match
                    ->where('products.name', 'like', $like)
                    ->orWhere('products.short_description', 'like', $like)
                    ->orWhere('products.description', 'like', $like)
                    ->orWhereHas('variants', fn (Builder $variants) => $variants->where('sku', 'like', $like));
            });
        }

        return $query;
    }

    /**
     * Products whose name holds every word come before those that matched only in the description.
     */
    public function rankByName(Builder $query, string $search): Builder
    {
        $terms = $this->terms($search);

        if ($terms === []) {
            return $query;
        }

        $conditions = implode(' AND ', array_fill(0, count($terms), 'products.name LIKE ?'));
        $bindings = array_map(fn (string $term): string => '%'.$this->escapeLike($term).'%', $terms);

        return $query->orderByRaw("CASE WHEN {$conditions} THEN 0 ELSE 1 END", $bindings);
    }

    /**
     * The query with each unknown word replaced by the closest word from product names, or null.
     * Compares without Polish diacritics, so "kregoslup" and "ortze" both find their words.
     */
    public function suggestion(string $search): ?string
    {
        $terms = $this->terms($search);

        if ($terms === []) {
            return null;
        }

        $vocabulary = $this->vocabulary();
        $changed = false;
        $suggested = [];

        foreach ($terms as $term) {
            $folded = Str::ascii($term);

            if (isset($vocabulary[$folded]) && $vocabulary[$folded] === $term) {
                $suggested[] = $term;

                continue;
            }

            $best = $vocabulary[$folded] ?? null;
            $bestDistance = $best === null ? PHP_INT_MAX : 0;

            if ($best === null) {
                $limit = mb_strlen($term) <= 4 ? 1 : 2;

                foreach ($vocabulary as $candidateFolded => $candidate) {
                    if (abs(strlen($candidateFolded) - strlen($folded)) > $limit) {
                        continue;
                    }

                    $distance = levenshtein($folded, (string) $candidateFolded);

                    if ($distance <= $limit && $distance < $bestDistance) {
                        [$best, $bestDistance] = [$candidate, $distance];
                    }
                }
            }

            $changed = $changed || ($best !== null && $best !== $term);
            $suggested[] = $best ?? $term;
        }

        return $changed ? implode(' ', $suggested) : null;
    }

    /**
     * Words of three or more letters from active product names, keyed by their form without diacritics.
     *
     * @return array<string, string>
     */
    private function vocabulary(): array
    {
        return $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_CATALOGUE,
            'search.vocabulary.v1',
            function (): array {
                $counts = [];

                Product::query()
                    ->where('status', ProductStatus::ACTIVE->value)
                    ->select(['id', 'name'])
                    ->chunkById(1000, function ($products) use (&$counts): void {
                        foreach ($products as $product) {
                            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string) $product->name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                                if (mb_strlen($word) >= 3) {
                                    $counts[$word] = ($counts[$word] ?? 0) + 1;
                                }
                            }
                        }
                    });

                // The most common spelling wins when two words fold to the same letters.
                arsort($counts);
                $vocabulary = [];

                foreach (array_keys($counts) as $word) {
                    $vocabulary[Str::ascii((string) $word)] ??= (string) $word;
                }

                return $vocabulary;
            },
            $this->cache->homePageTtlSeconds(),
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
