<?php

declare(strict_types=1);

namespace App\Services\Shop;

final class ShopSettings
{
    public function shopName(): string
    {
        return $this->string('legal.seller.shop_name', 'Konji Shop');
    }

    public function companyName(): string
    {
        return $this->string('legal.seller.company_name', $this->shopName());
    }

    public function representative(): string
    {
        return $this->string('legal.seller.representative');
    }

    public function street(): string
    {
        return $this->string('legal.seller.street');
    }

    public function postcode(): string
    {
        return $this->string('legal.seller.postcode');
    }

    public function city(): string
    {
        return $this->string('legal.seller.city');
    }

    public function country(): string
    {
        return $this->string('legal.seller.country', 'Poland');
    }

    public function email(): string
    {
        return $this->string('legal.seller.email');
    }

    public function phone(): string
    {
        return $this->string('legal.seller.phone');
    }

    public function taxId(): string
    {
        return $this->string('legal.seller.tax_id');
    }

    public function businessRegistryNumber(): string
    {
        return $this->string('legal.seller.business_registry_number');
    }

    public function returnAddress(): string
    {
        return $this->string('legal.returns.return_address');
    }

    public function returnsEmail(): string
    {
        return $this->string('legal.returns.contact_email', $this->email());
    }

    public function withdrawalDays(): int
    {
        return (int) config('legal.returns.withdrawal_days', 14);
    }

    public function termsVersion(): string
    {
        return $this->string('legal.versions.terms');
    }

    public function privacyVersion(): string
    {
        return $this->string('legal.versions.privacy');
    }

    public function returnsVersion(): string
    {
        return $this->string('legal.versions.returns');
    }

    /**
     * @return array{terms: string, privacy: string, returns: string}
     */
    public function legalVersions(): array
    {
        return [
            'terms' => $this->termsVersion(),
            'privacy' => $this->privacyVersion(),
            'returns' => $this->returnsVersion(),
        ];
    }

    /**
     * @return list<string>
     */
    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->street(),
            trim($this->postcode().' '.$this->city()),
            $this->country(),
        ], fn (string $line): bool => $line !== ''));
    }

    public function fullAddress(): string
    {
        return implode(', ', $this->addressLines());
    }

    public function phoneHref(): ?string
    {
        $phone = $this->phone();

        if ($phone === '') {
            return null;
        }

        return 'tel:'.preg_replace('/[^0-9+]/', '', $phone);
    }

    public function hasSellerIdentity(): bool
    {
        return $this->companyName() !== ''
            && $this->street() !== ''
            && $this->postcode() !== ''
            && $this->city() !== ''
            && $this->country() !== '';
    }

    public function hasContactDetails(): bool
    {
        return $this->email() !== ''
            && $this->phone() !== '';
    }

    public function hasLegalVersions(): bool
    {
        return $this->termsVersion() !== ''
            && $this->privacyVersion() !== ''
            && $this->returnsVersion() !== '';
    }

    private function string(string $key, string $default = ''): string
    {
        return trim((string) config($key, $default));
    }
}
