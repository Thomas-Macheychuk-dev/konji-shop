<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\AttributeValue;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Services\Storefront\ProductListing;

/**
 * The GPSR block of a product page: manufacturer name, postal and electronic address,
 * the EU responsible person for manufacturers outside the EU, and safety information.
 * Taken from the details an admin entered for the product's "Producent" (preferred) or "Marka" value.
 */
final class ProductManufacturerInformation
{
    /**
     * @return array{
     *     name: string,
     *     address: string,
     *     email: ?string,
     *     phone: ?string,
     *     website: ?string,
     *     eu_representative: ?array{name: string, address: ?string, email: ?string},
     *     safety_information: ?string
     * }|null
     */
    public static function forProduct(Product $product): ?array
    {
        $values = $product->relationLoaded('attributeValues')
            ? $product->attributeValues->loadMissing(['attribute', 'manufacturer'])
            : $product->attributeValues()->with(['attribute', 'manufacturer'])->get();

        foreach (ProductListing::BRAND_ATTRIBUTE_SLUGS as $slug) {
            $manufacturer = $values
                ->first(fn (AttributeValue $value): bool => $value->attribute?->slug === $slug && $value->manufacturer !== null)
                ?->manufacturer;

            if ($manufacturer instanceof Manufacturer) {
                return self::toArray($manufacturer);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Manufacturer $manufacturer): array
    {
        return [
            'name' => $manufacturer->name,
            'address' => $manufacturer->address,
            'email' => $manufacturer->email,
            'phone' => $manufacturer->phone,
            'website' => $manufacturer->website,
            'eu_representative' => $manufacturer->hasEuRepresentative()
                ? [
                    'name' => (string) $manufacturer->eu_representative_name,
                    'address' => $manufacturer->eu_representative_address,
                    'email' => $manufacturer->eu_representative_email,
                ]
                : null,
            'safety_information' => $manufacturer->safety_information,
        ];
    }
}
