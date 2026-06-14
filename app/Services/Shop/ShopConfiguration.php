<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\ShopConfigurationValue;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ShopConfiguration
{
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
            'seller_identity_address' => [
                'category' => 'Sprzedawca',
                'label' => 'Tożsamość i adres sprzedawcy',
                'config_key' => 'legal.seller.identity_address',
                'type' => 'textarea',
                'required' => true,
                'help' => 'Podaj nazwę albo firmę sprzedawcy oraz pełny adres widoczny dla klientów.',
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
                'required' => false,
            ],
            'return_address' => [
                'category' => 'Zwroty',
                'label' => 'Adres zwrotu',
                'config_key' => 'legal.returns.return_address',
                'type' => 'textarea',
                'required' => true,
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

    /**
     * @return array<string, string>
     */
    public function formValues(): array
    {
        $values = [];

        foreach ($this->editableFields() as $name => $field) {
            $values[$name] = $this->get($field['config_key']);
        }

        if ($values['seller_identity_address'] === '') {
            $values['seller_identity_address'] = $this->sellerIdentityAddressFallback();
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateFromForm(array $values): void
    {
        foreach ($this->editableFields() as $name => $field) {
            $value = trim((string) ($values[$name] ?? ''));

            ShopConfigurationValue::query()->updateOrCreate(
                ['key' => $field['config_key']],
                ['value' => $value],
            );
        }

        $this->applyConfigOverrides();
    }

    public function get(string $configKey, string $default = ''): string
    {
        try {
            if ($this->settingsTableExists()) {
                $setting = ShopConfigurationValue::query()
                    ->where('key', $configKey)
                    ->first(['value']);

                if ($setting !== null) {
                    return trim((string) $setting->value);
                }
            }
        } catch (Throwable) {
            // During first install, tests before migrations, or config-cache warmups the table
            // may not be available yet. In that case, keep using normal Laravel config.
        }

        return trim((string) config($configKey, $default));
    }

    public function applyConfigOverrides(): void
    {
        try {
            if (! $this->settingsTableExists()) {
                return;
            }

            $editableConfigKeys = collect($this->editableFields())
                ->pluck('config_key')
                ->all();

            ShopConfigurationValue::query()
                ->whereIn('key', $editableConfigKeys)
                ->get(['key', 'value'])
                ->each(function (ShopConfigurationValue $setting): void {
                    config()->set($setting->key, trim((string) $setting->value));
                });
        } catch (Throwable) {
            // Configuration overrides are optional at boot. The app must still boot while
            // migrations are pending or the database is temporarily unavailable.
        }
    }

    private function settingsTableExists(): bool
    {
        return Schema::hasTable('shop_configuration_values');
    }

    private function sellerIdentityAddressFallback(): string
    {
        $seller = config('legal.seller', []);

        if (! is_array($seller)) {
            return '';
        }

        return trim(implode(PHP_EOL, array_filter([
            trim((string) ($seller['company_name'] ?? '')),
            trim((string) ($seller['street'] ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($seller['postcode'] ?? '')),
                trim((string) ($seller['city'] ?? '')),
            ]))),
            trim((string) ($seller['country'] ?? '')),
        ], fn (string $line): bool => $line !== '')));
    }
}
