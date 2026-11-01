<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Enums\ProductStatus;
use App\Models\Product;

final class ReviewedProductAliases
{
    public function destination(Product $source): ?string
    {
        // Installing the code alone cannot activate redirects: only the reviewed
        // archive operation enables a pair. Reactivation restores the old page.
        if ($source->status !== ProductStatus::ARCHIVED) {
            return null;
        }
        $manifest = json_decode(file_get_contents(resource_path('seo/ortezka/reviewed-product-aliases-20261101.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['pairs'] as $pair) {
            if (! $this->matches($source, $pair['source'])) {
                continue;
            }
            $target = Product::query()->find($pair['target']['id']);
            if ($target === null || $target->status !== ProductStatus::ACTIVE || ! $this->matches($target, $pair['target'])) {
                return null;
            }

            return route('products.show', ['product' => $target->slug], false);
        }

        return null;
    }

    private function matches(Product $product, array $identity): bool
    {
        return (int) $product->id === $identity['id']
            && $product->slug === $identity['slug']
            && $product->external_source === $identity['external_source']
            && $product->external_id === $identity['external_id'];
    }
}
