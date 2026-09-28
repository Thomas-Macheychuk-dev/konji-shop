<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\ShopConfigurationValue;
use App\Services\Storefront\StorefrontCache;
use Throwable;

final class ShopConfiguration
{
    public function __construct(
        private readonly StorefrontCache $cache,
    ) {}

    /**
     * @return array<string, array{
     *     category: string,
     *     label: string,
     *     config_key: string,
     *     type: string,
     *     required: bool,
     *     secret?: bool,
     *     autocomplete?: string,
     *     help?: string
     * }>
     */
    public function editableFields(): array
    {
        return [
            'seller_company_name' => [
                'category' => 'Sprzedawca',
                'label' => 'Pełna nazwa firmy',
                'config_key' => 'legal.seller.company_name',
                'type' => 'text',
                'required' => true,
            ],
            'seller_street' => [
                'category' => 'Sprzedawca',
                'label' => 'Ulica i numer',
                'config_key' => 'legal.seller.street',
                'type' => 'text',
                'required' => true,
            ],
            'seller_postcode' => [
                'category' => 'Sprzedawca',
                'label' => 'Kod pocztowy',
                'config_key' => 'legal.seller.postcode',
                'type' => 'text',
                'required' => true,
            ],
            'seller_city' => [
                'category' => 'Sprzedawca',
                'label' => 'Miasto',
                'config_key' => 'legal.seller.city',
                'type' => 'text',
                'required' => true,
            ],
            'seller_country' => [
                'category' => 'Sprzedawca',
                'label' => 'Kraj',
                'config_key' => 'legal.seller.country',
                'type' => 'text',
                'required' => true,
            ],
            'seller_email' => [
                'category' => 'Sprzedawca',
                'label' => 'E-mail sprzedawcy',
                'config_key' => 'legal.seller.email',
                'type' => 'email',
                'required' => true,
            ],
            'seller_phone' => [
                'category' => 'Sprzedawca',
                'label' => 'Telefon sprzedawcy',
                'config_key' => 'legal.seller.phone',
                'type' => 'text',
                'required' => true,
            ],
            'seller_tax_id' => [
                'category' => 'Sprzedawca',
                'label' => 'NIP',
                'config_key' => 'legal.seller.tax_id',
                'type' => 'text',
                'required' => true,
            ],
            'seller_registry_number' => [
                'category' => 'Sprzedawca',
                'label' => 'KRS',
                'config_key' => 'legal.seller.business_registry_number',
                'type' => 'text',
                'required' => true,
            ],
            'seller_regon' => [
                'category' => 'Sprzedawca',
                'label' => 'REGON',
                'config_key' => 'legal.seller.regon',
                'type' => 'text',
                'required' => true,
            ],
            'seller_share_capital' => [
                'category' => 'Sprzedawca',
                'label' => 'Kapitał zakładowy',
                'config_key' => 'legal.seller.share_capital',
                'type' => 'text',
                'required' => true,
            ],
            'return_address' => [
                'category' => 'Zwroty',
                'label' => 'Adres zwrotów i reklamacji',
                'config_key' => 'legal.returns.return_address',
                'type' => 'textarea',
                'required' => true,
            ],
            'returns_email' => [
                'category' => 'Zwroty',
                'label' => 'E-mail do zwrotów i reklamacji',
                'config_key' => 'legal.returns.contact_email',
                'type' => 'email',
                'required' => true,
            ],
            'legal_terms_version' => [
                'category' => 'Prawo',
                'label' => 'Wersja Regulaminu',
                'config_key' => 'legal.versions.terms',
                'type' => 'text',
                'required' => true,
                'help' => 'Dla tej publikacji: 2026-09-24.',
            ],
            'legal_privacy_version' => [
                'category' => 'Prawo',
                'label' => 'Wersja Polityki prywatności',
                'config_key' => 'legal.versions.privacy',
                'type' => 'text',
                'required' => true,
                'help' => 'Dla tej publikacji: 2026-09-24.',
            ],
            'legal_returns_version' => [
                'category' => 'Prawo',
                'label' => 'Wersja informacji o zwrotach',
                'config_key' => 'legal.versions.returns',
                'type' => 'text',
                'required' => true,
                'help' => 'Dla tej publikacji: 2026-09-24.',
            ],
            'legal_effective_date' => [
                'category' => 'Prawo',
                'label' => 'Data wejścia w życie',
                'config_key' => 'legal.effective_date',
                'type' => 'date',
                'required' => true,
                'help' => 'Dla tej publikacji: 2026-09-28.',
            ],
            'mail_from_address' => [
                'category' => 'Poczta',
                'label' => 'Adres nadawcy e-mail',
                'config_key' => 'mail.from.address',
                'type' => 'email',
                'required' => true,
                'help' => 'Ten adres będzie używany jako From w wiadomościach wysyłanych przez sklep.',
            ],
            'polkurier_login' => [
                'category' => 'Dostawa',
                'label' => 'Login Polkurier',
                'config_key' => 'delivery.providers.polkurier.login',
                'type' => 'text',
                'required' => true,
                'autocomplete' => 'off',
            ],
            'polkurier_token' => [
                'category' => 'Dostawa',
                'label' => 'Token Polkurier',
                'config_key' => 'delivery.providers.polkurier.token',
                'type' => 'password',
                'required' => true,
                'secret' => true,
                'autocomplete' => 'off',
            ],
        ];
    }

    /** @return array<string, string> */
    public function formValues(): array
    {
        $values = [];

        foreach ($this->editableFields() as $name => $field) {
            $values[$name] = $this->get($field['config_key']);
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    public function updateFromForm(array $values): void
    {
        foreach ($this->editableFields() as $name => $field) {
            $value = trim((string) ($values[$name] ?? ''));

            ShopConfigurationValue::query()->updateOrCreate(
                ['key' => $field['config_key']],
                ['value' => $value],
            );
        }

        $this->cache->bump(StorefrontCache::NAMESPACE_SHOP_CONFIGURATION);
        $this->applyConfigOverrides();
    }

    public function get(string $configKey, string $default = ''): string
    {
        $overrides = $this->cachedOverrides();

        if (array_key_exists($configKey, $overrides)) {
            return trim((string) $overrides[$configKey]);
        }

        return trim((string) config($configKey, $default));
    }

    public function applyConfigOverrides(): void
    {
        foreach ($this->cachedOverrides() as $key => $value) {
            config()->set($key, trim((string) $value));
        }
    }

    /** @return array<string, string> */
    private function cachedOverrides(): array
    {
        try {
            return $this->cache->rememberVersioned(
                StorefrontCache::NAMESPACE_SHOP_CONFIGURATION,
                'editable-values.v2',
                function (): array {
                    $editableConfigKeys = collect($this->editableFields())
                        ->pluck('config_key')
                        ->all();

                    return ShopConfigurationValue::query()
                        ->whereIn('key', $editableConfigKeys)
                        ->pluck('value', 'key')
                        ->map(fn (mixed $value): string => trim((string) $value))
                        ->all();
                },
                $this->cache->shopConfigurationTtlSeconds(),
            );
        } catch (Throwable) {
            return [];
        }
    }
}
