<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveManufacturerRequest;
use App\Models\AttributeValue;
use App\Services\Storefront\ProductListing;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Http\RedirectResponse;

final class AdminManufacturerUpdateController extends Controller
{
    public function __invoke(SaveManufacturerRequest $request, AttributeValue $attributeValue, StorefrontCache $cache): RedirectResponse
    {
        $attributeValue->loadMissing('attribute');

        abort_unless(in_array($attributeValue->attribute?->slug, ProductListing::BRAND_ATTRIBUTE_SLUGS, true), 404);

        $attributeValue->manufacturer()->updateOrCreate([], $request->validated());

        // Product pages are cached with the manufacturer block in them.
        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);

        return back()->with('success', 'Zapisano dane producenta. Są widoczne na stronach produktów marki „'.$attributeValue->value.'”.');
    }
}
