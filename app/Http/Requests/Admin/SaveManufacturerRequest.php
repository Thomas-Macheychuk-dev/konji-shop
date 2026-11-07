<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class SaveManufacturerRequest extends FormRequest
{
    private const FIELDS = [
        'name',
        'address',
        'email',
        'phone',
        'website',
        'eu_representative_name',
        'eu_representative_address',
        'eu_representative_email',
        'safety_information',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(self::FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $this->nullableString($this->input($field))])
            ->all());
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            // The GPSR asks for a postal and an electronic address: an e-mail or a website.
            'email' => ['nullable', 'required_without:website', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'required_without:email', 'url:http,https', 'max:255'],
            'eu_representative_name' => ['nullable', 'required_with:eu_representative_address,eu_representative_email', 'string', 'max:255'],
            'eu_representative_address' => ['nullable', 'required_with:eu_representative_name', 'string', 'max:1000'],
            'eu_representative_email' => ['nullable', 'email', 'max:255'],
            'safety_information' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_without' => 'Podaj e-mail albo stronę internetową producenta.',
            'website.required_without' => 'Podaj e-mail albo stronę internetową producenta.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nazwa producenta',
            'address' => 'adres producenta',
            'email' => 'e-mail',
            'phone' => 'telefon',
            'website' => 'strona internetowa',
            'eu_representative_name' => 'nazwa osoby odpowiedzialnej w UE',
            'eu_representative_address' => 'adres osoby odpowiedzialnej w UE',
            'eu_representative_email' => 'e-mail osoby odpowiedzialnej w UE',
            'safety_information' => 'informacje dotyczące bezpieczeństwa',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\r\n", "\n", (string) $value));

        return $value === '' ? null : $value;
    }
}
