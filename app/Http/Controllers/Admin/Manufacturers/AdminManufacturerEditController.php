<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\AttributeValue;
use App\Models\Manufacturer;
use App\Services\Storefront\ProductListing;
use Illuminate\Contracts\View\View;

final class AdminManufacturerEditController extends Controller
{
    public function __invoke(AttributeValue $attributeValue): View
    {
        $attributeValue->loadMissing(['attribute', 'manufacturer']);

        abort_unless(in_array($attributeValue->attribute?->slug, ProductListing::BRAND_ATTRIBUTE_SLUGS, true), 404);

        return view('admin.manufacturers.edit', [
            'brand' => $attributeValue,
            'manufacturer' => $attributeValue->manufacturer ?? new Manufacturer(['name' => $attributeValue->value]),
            'activeProductCount' => $attributeValue->products()->where('status', ProductStatus::ACTIVE->value)->count(),
            'sampleProducts' => $attributeValue->products()
                ->where('status', ProductStatus::ACTIVE->value)
                ->orderBy('name')
                ->limit(5)
                ->get(['products.id', 'products.name', 'products.slug']),
        ]);
    }
}
