<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductDefaultImageRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminProductDefaultImageUpdateController extends Controller
{
    public function __invoke(
        UpdateProductDefaultImageRequest $request,
        Product $product,
    ): RedirectResponse {
        $type = $request->defaultImageType();
        $imageId = $request->defaultImageId();

        DB::transaction(function () use ($product, $type, $imageId): void {
            match ($type) {
                Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE => $this->selectProductImage($product, $imageId),
                Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE => $this->selectAttributeValueImage($product, $imageId),
                default => throw ValidationException::withMessages([
                    'default_image' => __('The selected default image type is invalid.'),
                ]),
            };

            $product->update([
                'default_image_type' => $type,
                'default_image_id' => $imageId,
            ]);
        });

        return back()->with('success', 'Default product image updated.');
    }

    private function selectProductImage(Product $product, int $imageId): void
    {
        $image = $product->images()
            ->whereKey($imageId)
            ->first();

        if ($image === null) {
            throw ValidationException::withMessages([
                'default_image' => __('The selected image does not belong to this product.'),
            ]);
        }

        $product->images()->update([
            'is_main' => false,
        ]);

        $image->update([
            'is_main' => true,
        ]);
    }

    private function selectAttributeValueImage(Product $product, int $imageId): void
    {
        $image = $product->attributeValueImages()
            ->whereKey($imageId)
            ->first();

        if ($image === null) {
            throw ValidationException::withMessages([
                'default_image' => __('The selected image does not belong to this product.'),
            ]);
        }
    }
}
