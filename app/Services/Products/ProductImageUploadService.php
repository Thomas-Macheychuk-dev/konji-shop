<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProductImageUploadService
{
    /**
     * @param  array<int, UploadedFile>  $uploadedImages
     * @return Collection<int, ProductImage>
     */
    public function upload(Product $product, array $uploadedImages): Collection
    {
        return DB::transaction(function () use ($product, $uploadedImages): Collection {
            $uploadedImages = array_values(array_filter(
                $uploadedImages,
                fn (mixed $uploadedImage): bool => $uploadedImage instanceof UploadedFile && $uploadedImage->isValid(),
            ));

            if ($uploadedImages === []) {
                return collect();
            }

            $currentMaxSortOrder = $product->images()->max('sort_order');
            $nextSortOrder = $currentMaxSortOrder === null ? 0 : ((int) $currentMaxSortOrder) + 1;
            $hasMainProductImage = $product->images()
                ->where('is_main', true)
                ->exists();
            $shouldUseFirstUploadedImageAsDefault = $product->selectedDefaultImage() === null;
            $uploadedProductImages = collect();
            $firstImage = null;

            foreach ($uploadedImages as $index => $uploadedImage) {
                $extension = $uploadedImage->extension() ?: $uploadedImage->guessExtension() ?: 'jpg';
                $mimeType = $uploadedImage->getMimeType();
                $fileSize = $uploadedImage->getSize();
                $realPath = $uploadedImage->getRealPath();
                $sha256 = is_string($realPath) && $realPath !== '' ? (hash_file('sha256', $realPath) ?: null) : null;
                $directory = 'products/manual/'.$product->id.'/gallery';
                $path = $uploadedImage->storeAs(
                    $directory,
                    (string) Str::uuid().'.'.$extension,
                    'public'
                );

                if (! is_string($path) || $path === '') {
                    continue;
                }

                $image = ProductImage::query()->create([
                    'product_id' => $product->id,
                    'disk' => 'public',
                    'path' => $path,
                    'source_url' => null,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'sha256' => $sha256,
                    'alt_text' => $product->name,
                    'title' => $product->name,
                    'sort_order' => $nextSortOrder + $index,
                    'is_main' => ! $hasMainProductImage && $index === 0,
                ]);

                $firstImage ??= $image;
                $uploadedProductImages->push($image);
            }

            if ($shouldUseFirstUploadedImageAsDefault && $firstImage instanceof ProductImage) {
                $product->update([
                    'default_image_type' => Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE,
                    'default_image_id' => $firstImage->id,
                ]);
            }

            return $uploadedProductImages;
        });
    }
}
