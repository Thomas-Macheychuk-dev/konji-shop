<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Storefront pages whose texts admins edit in "Edytor stron". The value is the
 * public URL path and the `pages.slug` key; the Blade defaults stay in use until
 * an admin saves a different text.
 */
enum EditablePage: string
{
    case RENTAL = 'wypozyczalnia-sprzetu-medycznego';
    case DELIVERY_AND_PAYMENTS = 'delivery-and-payments';
    case RETURNS_AND_WITHDRAWAL = 'returns-and-withdrawal';
    case COMPLAINTS_AND_WARRANTY = 'complaints-and-warranty';
    case CONTACT = 'contact';
    case NFZ = 'refundacje-nfz';

    public function routeName(): string
    {
        return match ($this) {
            self::RENTAL => 'rental.index',
            self::DELIVERY_AND_PAYMENTS => 'legal.delivery-payments',
            self::RETURNS_AND_WITHDRAWAL => 'legal.returns',
            self::COMPLAINTS_AND_WARRANTY => 'legal.complaints',
            self::CONTACT => 'legal.contact',
            self::NFZ => 'legal.nfz',
        };
    }

    public function view(): string
    {
        return match ($this) {
            self::RENTAL => 'pages.rental',
            self::DELIVERY_AND_PAYMENTS => 'pages.legal.delivery-and-payments',
            self::RETURNS_AND_WITHDRAWAL => 'pages.legal.returns-and-withdrawal',
            self::COMPLAINTS_AND_WARRANTY => 'pages.legal.complaints-and-warranty',
            self::CONTACT => 'pages.legal.contact',
            self::NFZ => 'pages.legal.nfz',
        };
    }

    public function defaultTitle(): string
    {
        return match ($this) {
            self::RENTAL => 'Wypożyczalnia sprzętu medycznego',
            self::DELIVERY_AND_PAYMENTS => 'Dostawa i płatności',
            self::RETURNS_AND_WITHDRAWAL => 'Zwroty i odstąpienie od umowy',
            self::COMPLAINTS_AND_WARRANTY => 'Reklamacje – niezgodność towaru z umową',
            self::CONTACT => 'Kontakt',
            self::NFZ => 'Refundacje NFZ',
        };
    }

    public function defaultSeoTitle(): ?string
    {
        return match ($this) {
            self::RENTAL => 'Wypożyczalnia sprzętu medycznego Poznań',
            self::NFZ => 'Refundacje NFZ',
            default => null,
        };
    }

    public function defaultSeoDescription(): ?string
    {
        return match ($this) {
            self::RENTAL => 'Wypożycz łóżko rehabilitacyjne, koncentrator tlenu, wózek inwalidzki, podnośnik lub schodołaz w Poznaniu. Dostawa, wniesienie i przygotowanie do użycia.',
            self::NFZ => 'Realizacja zleceń NFZ na wyroby medyczne w Poznaniu. Sprawdź, jak zrealizować zlecenie i odebrać wyrób medyczny.',
            default => null,
        };
    }

    public function defaultIntroView(): string
    {
        return 'pages.editable.'.$this->value.'.intro';
    }

    public function defaultContentView(): string
    {
        return 'pages.editable.'.$this->value.'.content';
    }

    /** Where the HTML content sits on the page, shown next to the editor. */
    public function contentHint(): string
    {
        return match ($this) {
            self::RENTAL => 'Dodatkowa informacja w niebieskim nagłówku, pod wstępem (np. pozostała oferta).',
            self::CONTACT => 'Bloki z danymi kontaktowymi, adresem zwrotów i danymi sprzedawcy.',
            default => 'Główna treść strony pod wyróżnionymi informacjami.',
        };
    }
}
