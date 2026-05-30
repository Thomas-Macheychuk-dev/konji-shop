<?php

declare(strict_types=1);

namespace App\Services\Shop;

final class ShopReadinessCheck
{
    public function __construct(
        private readonly ShopSettings $settings,
    ) {}

    /**
     * @return list<array{
     *     category: string,
     *     name: string,
     *     status: string,
     *     required: bool,
     *     message: string
     * }>
     */
    public function items(): array
    {
        return [
            $this->checkLegalVersions(),
            $this->checkSellerIdentity(),
            $this->checkSellerEmail(),
            $this->checkSellerPhone(),
            $this->checkTaxId(),
            $this->checkReturnAddress(),
            $this->checkAppUrl(),
            $this->checkAppDebug(),
            $this->checkPaymentDefaultProvider(),
            $this->checkMailFromAddress(),
            $this->checkPolkurierBaseUrl(),
            $this->checkPolkurierLogin(),
            $this->checkPolkurierToken(),
        ];
    }

    public function isReady(): bool
    {
        foreach ($this->items() as $item) {
            if ($item['required'] && $item['status'] !== 'ready') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     ready: bool,
     *     items: list<array{
     *         category: string,
     *         name: string,
     *         status: string,
     *         required: bool,
     *         message: string
     *     }>
     * }
     */
    public function summary(): array
    {
        return [
            'ready' => $this->isReady(),
            'items' => $this->items(),
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkLegalVersions(): array
    {
        if ($this->settings->hasLegalVersions()) {
            return $this->ready(
                'Legal',
                'Legal document versions',
                true,
                'Terms, privacy, and returns policy versions are configured.'
            );
        }

        return $this->missing(
            'Legal',
            'Legal document versions',
            true,
            'Terms, privacy, and returns policy versions must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkSellerIdentity(): array
    {
        if ($this->settings->hasSellerIdentity()) {
            return $this->ready(
                'Seller',
                'Seller identity and address',
                true,
                'Seller company name and address are configured.'
            );
        }

        return $this->missing(
            'Seller',
            'Seller identity and address',
            true,
            'Seller company name, street, postcode, city, and country must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkSellerEmail(): array
    {
        if ($this->settings->email() !== '') {
            return $this->ready(
                'Seller',
                'Seller email',
                true,
                'Seller email is configured.'
            );
        }

        return $this->missing(
            'Seller',
            'Seller email',
            true,
            'Seller contact email must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkSellerPhone(): array
    {
        if ($this->settings->phone() !== '') {
            return $this->ready(
                'Seller',
                'Seller phone',
                true,
                'Seller phone number is configured.'
            );
        }

        return $this->missing(
            'Seller',
            'Seller phone',
            true,
            'Seller phone number must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkTaxId(): array
    {
        if ($this->settings->taxId() !== '') {
            return $this->ready(
                'Seller',
                'Tax ID',
                false,
                'Seller tax ID is configured.'
            );
        }

        return $this->warning(
            'Seller',
            'Tax ID',
            false,
            'Seller tax ID is empty. Add it before production if the business should display one.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkReturnAddress(): array
    {
        if ($this->settings->returnAddress() !== '') {
            return $this->ready(
                'Returns',
                'Return address',
                true,
                'Return address is configured.'
            );
        }

        return $this->missing(
            'Returns',
            'Return address',
            true,
            'Return address must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkAppUrl(): array
    {
        $appUrl = trim((string) config('app.url'));

        if ($appUrl === '') {
            return $this->missing(
                'Application',
                'APP_URL',
                true,
                'APP_URL must be configured.'
            );
        }

        if (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            return $this->warning(
                'Application',
                'APP_URL',
                false,
                'APP_URL points to a local address. Use the public production URL before going live.'
            );
        }

        return $this->ready(
            'Application',
            'APP_URL',
            true,
            'APP_URL is configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkAppDebug(): array
    {
        if ((bool) config('app.debug') === false) {
            return $this->ready(
                'Application',
                'APP_DEBUG',
                true,
                'APP_DEBUG is disabled.'
            );
        }

        return $this->missing(
            'Application',
            'APP_DEBUG',
            true,
            'APP_DEBUG must be false in production.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPaymentDefaultProvider(): array
    {
        $defaultProvider = trim((string) config('payments.default'));

        if ($defaultProvider === '') {
            return $this->missing(
                'Payments',
                'Default payment provider',
                true,
                'Default payment provider must be configured.'
            );
        }

        return $this->ready(
            'Payments',
            'Default payment provider',
            true,
            'Default payment provider is configured: '.$defaultProvider.'.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkMailFromAddress(): array
    {
        $mailFromAddress = trim((string) config('mail.from.address'));

        if ($mailFromAddress === '') {
            return $this->missing(
                'Mail',
                'Mail from address',
                true,
                'Mail from address must be configured.'
            );
        }

        return $this->ready(
            'Mail',
            'Mail from address',
            true,
            'Mail from address is configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPolkurierBaseUrl(): array
    {
        if (trim((string) config('delivery.providers.polkurier.base_url')) !== '') {
            return $this->ready(
                'Delivery',
                'Polkurier base URL',
                true,
                'Polkurier base URL is configured.'
            );
        }

        return $this->missing(
            'Delivery',
            'Polkurier base URL',
            true,
            'Polkurier base URL must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPolkurierLogin(): array
    {
        if (trim((string) config('delivery.providers.polkurier.login')) !== '') {
            return $this->ready(
                'Delivery',
                'Polkurier login',
                true,
                'Polkurier login is configured.'
            );
        }

        return $this->missing(
            'Delivery',
            'Polkurier login',
            true,
            'Polkurier login must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPolkurierToken(): array
    {
        if (trim((string) config('delivery.providers.polkurier.token')) !== '') {
            return $this->ready(
                'Delivery',
                'Polkurier token',
                true,
                'Polkurier token is configured.'
            );
        }

        return $this->missing(
            'Delivery',
            'Polkurier token',
            true,
            'Polkurier token must be configured.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function ready(string $category, string $name, bool $required, string $message): array
    {
        return [
            'category' => $category,
            'name' => $name,
            'status' => 'ready',
            'required' => $required,
            'message' => $message,
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function warning(string $category, string $name, bool $required, string $message): array
    {
        return [
            'category' => $category,
            'name' => $name,
            'status' => 'warning',
            'required' => $required,
            'message' => $message,
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function missing(string $category, string $name, bool $required, string $message): array
    {
        return [
            'category' => $category,
            'name' => $name,
            'status' => 'missing',
            'required' => $required,
            'message' => $message,
        ];
    }
}
