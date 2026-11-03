<?php

declare(strict_types=1);

namespace App\Support\Rental;

use App\Models\RentalItem;
use App\Services\Shop\ShopSettings;
use Illuminate\Support\Collection;

/**
 * schema.org markup for the rental pages. Prices are monthly rental fees, so
 * offers use a lease business function with a per-month unit price instead of
 * a plain purchase price.
 */
final class RentalStructuredData
{
    public function __construct(
        private readonly ShopSettings $shopSettings,
    ) {}

    /**
     * @param  Collection<int, RentalItem>  $items
     * @return array<string, mixed>
     */
    public function business(Collection $items, string $name, string $description): array
    {
        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'MedicalBusiness',
            '@id' => route('rental.index').'#wypozyczalnia',
            'name' => $name,
            'description' => $description,
            'url' => route('rental.index'),
            'image' => $items->first(fn (RentalItem $item): bool => $item->imageUrl() !== null)?->imageUrl(),
            'telephone' => array_values(array_filter(config('rental.phones', [])))[0] ?? null,
            'address' => $this->address(),
            'areaServed' => ['@type' => 'City', 'name' => 'Poznań'],
            'parentOrganization' => $this->withoutEmpty([
                '@type' => 'Organization',
                'name' => $this->shopSettings->companyName(),
                'url' => route('home'),
            ]),
            'hasOfferCatalog' => [
                '@type' => 'OfferCatalog',
                'name' => 'Sprzęt medyczny do wypożyczenia',
                'itemListElement' => $items
                    ->groupBy('group')
                    ->map(fn (Collection $groupItems, string $group): array => [
                        '@type' => 'OfferCatalog',
                        'name' => RentalItem::GROUPS[$group] ?? $group,
                        'itemListElement' => $groupItems->map(fn (RentalItem $item): array => [
                            ...$this->offer($item),
                            'itemOffered' => ['@type' => 'Product', 'name' => $item->name, 'url' => $item->url()],
                        ])->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function item(RentalItem $item): array
    {
        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $item->name,
            'description' => $item->seoDescription(),
            'url' => $item->url(),
            'image' => $item->imageUrl(),
            'category' => $item->groupHeading(),
            'offers' => $this->offer($item),
        ]);
    }

    /**
     * @param  array<int, array{name: string, url: string}>  $crumbs
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $crumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $crumb, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ], $crumbs, array_keys($crumbs)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(RentalItem $item): array
    {
        return $this->withoutEmpty([
            '@type' => 'Offer',
            'url' => $item->url(),
            'businessFunction' => 'http://purl.org/goodrelations/v1#LeaseOut',
            'availability' => 'https://schema.org/InStock',
            'priceCurrency' => 'PLN',
            'price' => $item->monthly_min,
            'priceSpecification' => $this->withoutEmpty([
                '@type' => 'UnitPriceSpecification',
                'price' => $item->monthly_min,
                'minPrice' => $item->monthly_max !== null ? $item->monthly_min : null,
                'maxPrice' => $item->monthly_max,
                'priceCurrency' => 'PLN',
                'unitCode' => 'MON',
                'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => 'MON'],
            ]),
            'areaServed' => ['@type' => 'City', 'name' => 'Poznań'],
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function address(): ?array
    {
        $address = trim((string) config('rental.address'));

        if ($address === '') {
            return null;
        }

        // "Bolesława Prusa 20, 60-820 Poznań"
        if (preg_match('/^(?<street>.+),\s*(?<postcode>\d{2}-\d{3})\s+(?<city>.+)$/u', $address, $parts) === 1) {
            return [
                '@type' => 'PostalAddress',
                'streetAddress' => $parts['street'],
                'postalCode' => $parts['postcode'],
                'addressLocality' => $parts['city'],
                'addressCountry' => 'PL',
            ];
        }

        return ['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressCountry' => 'PL'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
