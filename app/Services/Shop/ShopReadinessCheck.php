<?php

declare(strict_types=1);

namespace App\Services\Shop;

final class ShopReadinessCheck
{
    public function __construct(
        private readonly ShopSettings $settings,
        private readonly ShopConfiguration $configuration,
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
                'Prawo',
                'Wersje dokumentów prawnych',
                true,
                'Wersje regulaminu, polityki prywatności i zwrotów są skonfigurowane.'
            );
        }

        return $this->missing(
            'Prawo',
            'Wersje dokumentów prawnych',
            true,
            'Wersje regulaminu, polityki prywatności i zwrotów muszą być skonfigurowane.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkSellerIdentity(): array
    {
        if ($this->settings->hasSellerIdentity()) {
            return $this->ready(
                'Sprzedawca',
                'Tożsamość i adres sprzedawcy',
                true,
                'Nazwa firmy i adres sprzedawcy są skonfigurowane.'
            );
        }

        return $this->missing(
            'Sprzedawca',
            'Tożsamość i adres sprzedawcy',
            true,
            'Nazwa firmy, ulica, kod pocztowy, miasto i kraj sprzedawcy muszą być skonfigurowane.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkSellerEmail(): array
    {
        if ($this->settings->email() !== '') {
            return $this->ready(
                'Sprzedawca',
                'E-mail sprzedawcy',
                true,
                'E-mail sprzedawcy jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Sprzedawca',
            'E-mail sprzedawcy',
            true,
            'Kontaktowy e-mail sprzedawcy musi być skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkSellerPhone(): array
    {
        if ($this->settings->phone() !== '') {
            return $this->ready(
                'Sprzedawca',
                'Telefon sprzedawcy',
                true,
                'Telefon sprzedawcy jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Sprzedawca',
            'Telefon sprzedawcy',
            true,
            'Telefon sprzedawcy musi być skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkTaxId(): array
    {
        if ($this->settings->taxId() !== '') {
            return $this->ready(
                'Sprzedawca',
                'NIP',
                false,
                'NIP sprzedawcy jest skonfigurowany.'
            );
        }

        return $this->warning(
            'Sprzedawca',
            'NIP',
            false,
            'NIP sprzedawcy jest pusty. Dodaj go przed produkcją, jeżeli firma powinna go wyświetlać.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkReturnAddress(): array
    {
        if ($this->settings->returnAddress() !== '') {
            return $this->ready(
                'Zwroty',
                'Adres zwrotu',
                true,
                'Adres zwrotu jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Zwroty',
            'Adres zwrotu',
            true,
            'Adres zwrotu musi być skonfigurowany.'
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
                'Aplikacja',
                'APP_URL',
                true,
                'APP_URL musi być skonfigurowany.'
            );
        }

        if (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            return $this->warning(
                'Aplikacja',
                'APP_URL',
                false,
                'APP_URL wskazuje na adres lokalny. Przed uruchomieniem użyj publicznego adresu produkcyjnego.'
            );
        }

        return $this->ready(
            'Aplikacja',
            'APP_URL',
            true,
            'APP_URL jest skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkAppDebug(): array
    {
        if ((bool) config('app.debug') === false) {
            return $this->ready(
                'Aplikacja',
                'APP_DEBUG',
                true,
                'APP_DEBUG jest wyłączony.'
            );
        }

        return $this->missing(
            'Aplikacja',
            'APP_DEBUG',
            true,
            'APP_DEBUG musi mieć wartość false na produkcji.'
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
                'Płatności',
                'Domyślny operator płatności',
                true,
                'Domyślny operator płatności musi być skonfigurowany.'
            );
        }

        return $this->ready(
            'Płatności',
            'Domyślny operator płatności',
            true,
            'Domyślny operator płatności jest skonfigurowany: '.$defaultProvider.'.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkMailFromAddress(): array
    {
        $mailFromAddress = $this->configuration->get('mail.from.address');

        if ($mailFromAddress === '') {
            return $this->missing(
                'Poczta',
                'Adres nadawcy e-mail',
                true,
                'Adres nadawcy e-mail musi być skonfigurowany.'
            );
        }

        return $this->ready(
            'Poczta',
            'Adres nadawcy e-mail',
            true,
            'Adres nadawcy e-mail jest skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPolkurierBaseUrl(): array
    {
        if (trim((string) config('delivery.providers.polkurier.base_url')) !== '') {
            return $this->ready(
                'Dostawa',
                'Bazowy URL Polkurier',
                true,
                'Bazowy URL Polkurier jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Dostawa',
            'Bazowy URL Polkurier',
            true,
            'Bazowy URL Polkurier musi być skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPolkurierLogin(): array
    {
        if ($this->configuration->get('delivery.providers.polkurier.login') !== '') {
            return $this->ready(
                'Dostawa',
                'Login Polkurier',
                true,
                'Login Polkurier jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Dostawa',
            'Login Polkurier',
            true,
            'Login Polkurier musi być skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPolkurierToken(): array
    {
        if ($this->configuration->get('delivery.providers.polkurier.token') !== '') {
            return $this->ready(
                'Dostawa',
                'Token Polkurier',
                true,
                'Token Polkurier jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Dostawa',
            'Token Polkurier',
            true,
            'Token Polkurier musi być skonfigurowany.'
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
