<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyDataKey: string
{
    case NAME = 'name';
    case LEGAL_NAME = 'legal_name';
    case TAX_ID = 'tax_id';
    case REGON = 'regon';
    case KRS = 'krs';
    case EMAIL = 'email';
    case PHONE = 'phone';
    case STREET = 'street';
    case POSTAL_CODE = 'postal_code';
    case CITY = 'city';
    case COUNTRY = 'country';
    case BANK_ACCOUNT = 'bank_account';

    public function translationKey(): string
    {
        return 'enums.company_data_key.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
