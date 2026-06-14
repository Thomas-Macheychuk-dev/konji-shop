<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductImagesRequest;
use App\Models\Product;
use App\Services\Products\ProductImageUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

final class AdminProductImageStoreController extends Controller
{
    public function __invoke(
        StoreProductImagesRequest $request,
        Product $product,
        ProductImageUploadService $imageUploadService,
    ): RedirectResponse {
        $uploadedImages = $request->file('product_images', []);

        if ($uploadedImages instanceof UploadedFile) {
            $uploadedImages = [$uploadedImages];
        }

        $images = $imageUploadService->upload($product, $uploadedImages);

        return back()->with(
            'success',
            trans_choice(
                'Dodano :count zdjęcie produktu.|Dodano :count zdjęcia produktu.|Dodano :count zdjęć produktu.',
                $images->count(),
                ['count' => $images->count()],
            ),
        );
    }
}
