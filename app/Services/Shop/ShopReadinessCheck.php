<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\PaymentProvider;
use Illuminate\Support\Facades\Route;

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
            $this->checkPaynowApiKey(),
            $this->checkPaynowSignatureKey(),
            $this->checkPaynowProductionMode(),
            $this->checkPaynowNotificationPath(),
            $this->checkPaynowReturnPath(),
            $this->checkMailTransport(),
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

        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            return $this->missing(
                'Aplikacja',
                'APP_URL',
                true,
                'APP_URL musi być poprawnym publicznym adresem HTTPS.'
            );
        }

        $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        if ($scheme !== 'https' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $this->missing(
                'Aplikacja',
                'APP_URL',
                true,
                'APP_URL musi wskazywać na publiczny adres HTTPS na produkcji.'
            );
        }

        return $this->ready(
            'Aplikacja',
            'APP_URL',
            true,
            'APP_URL wskazuje na publiczny adres HTTPS.'
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

        if ($defaultProvider !== PaymentProvider::PAYNOW->value) {
            return $this->missing(
                'Płatności',
                'Domyślny operator płatności',
                true,
                'Paynow musi być domyślnym operatorem płatności na produkcji.'
            );
        }

        return $this->ready(
            'Płatności',
            'Domyślny operator płatności',
            true,
            'Paynow jest domyślnym operatorem płatności.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPaynowApiKey(): array
    {
        if (trim((string) config('payments.providers.paynow.api_key')) !== '') {
            return $this->ready(
                'Płatności',
                'Paynow API key',
                true,
                'Klucz API Paynow jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Płatności',
            'Paynow API key',
            true,
            'Klucz API Paynow musi być skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPaynowSignatureKey(): array
    {
        if (trim((string) config('payments.providers.paynow.signature_key')) !== '') {
            return $this->ready(
                'Płatności',
                'Paynow signature key',
                true,
                'Klucz podpisu Paynow jest skonfigurowany.'
            );
        }

        return $this->missing(
            'Płatności',
            'Paynow signature key',
            true,
            'Klucz podpisu Paynow musi być skonfigurowany.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPaynowProductionMode(): array
    {
        if ((bool) config('payments.providers.paynow.sandbox', true) === false) {
            return $this->ready(
                'Płatności',
                'Paynow tryb produkcyjny',
                true,
                'Paynow działa w trybie produkcyjnym.'
            );
        }

        return $this->missing(
            'Płatności',
            'Paynow tryb produkcyjny',
            true,
            'PAYNOW_SANDBOX musi mieć wartość false na produkcji.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPaynowNotificationPath(): array
    {
        return $this->checkPaynowRoutePath(
            configKey: 'payments.providers.paynow.notification_path',
            routeName: 'payments.paynow.notifications',
            itemName: 'Paynow notification path',
            readyMessage: 'Ścieżka powiadomień Paynow jest zgodna z trasą aplikacji.',
            missingMessage: 'PAYNOW_NOTIFICATION_PATH musi dokładnie odpowiadać trasie powiadomień Paynow.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPaynowReturnPath(): array
    {
        return $this->checkPaynowRoutePath(
            configKey: 'payments.providers.paynow.return_path',
            routeName: 'checkout.success',
            itemName: 'Paynow return path',
            readyMessage: 'Ścieżka powrotu Paynow jest zgodna z trasą aplikacji.',
            missingMessage: 'PAYNOW_RETURN_PATH musi dokładnie odpowiadać trasie powrotu po płatności.'
        );
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkPaynowRoutePath(
        string $configKey,
        string $routeName,
        string $itemName,
        string $readyMessage,
        string $missingMessage,
    ): array {
        $configuredPath = trim((string) config($configKey));

        if ($configuredPath === '' || ! Route::has($routeName)) {
            return $this->missing('Płatności', $itemName, true, $missingMessage);
        }

        $expectedPath = route($routeName, [], false);

        if ($configuredPath !== $expectedPath) {
            return $this->missing('Płatności', $itemName, true, $missingMessage);
        }

        return $this->ready('Płatności', $itemName, true, $readyMessage);
    }

    /**
     * @return array{category: string, name: string, status: string, required: bool, message: string}
     */
    private function checkMailTransport(): array
    {
        $mailerName = trim((string) config('mail.default'));
        $mailer = $mailerName === '' ? null : config('mail.mailers.'.$mailerName);

        if (! is_array($mailer)) {
            return $this->missing(
                'Poczta',
                'Transport e-mail',
                true,
                'Domyślny transport e-mail musi być skonfigurowany.'
            );
        }

        $transport = strtolower(trim((string) ($mailer['transport'] ?? '')));

        if ($transport === '' || in_array($transport, ['array', 'log'], true)) {
            return $this->missing(
                'Poczta',
                'Transport e-mail',
                true,
                'Produkcja musi używać rzeczywistego transportu e-mail.'
            );
        }

        if ($transport === 'smtp') {
            $host = strtolower(trim((string) ($mailer['host'] ?? '')));
            $port = (int) ($mailer['port'] ?? 0);
            $username = trim((string) ($mailer['username'] ?? ''));
            $password = trim((string) ($mailer['password'] ?? ''));

            if (
                $host === ''
                || in_array($host, ['localhost', '127.0.0.1', 'mailpit'], true)
                || $port <= 0
                || $username === ''
                || $password === ''
            ) {
                return $this->missing(
                    'Poczta',
                    'Transport e-mail',
                    true,
                    'SMTP wymaga produkcyjnego hosta, portu, użytkownika i hasła.'
                );
            }
        }

        return $this->ready(
            'Poczta',
            'Transport e-mail',
            true,
            'Produkcja ma skonfigurowany rzeczywisty transport e-mail.'
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
