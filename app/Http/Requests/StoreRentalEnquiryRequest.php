<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RentalItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRentalEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s-]{6,}$/'],
            'items' => ['nullable', 'array', 'max:10'],
            'items.*' => ['string', Rule::exists(RentalItem::class, 'slug')->where('is_active', true)],
            'period' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            // Honeypot: hidden from people, filled in by bots.
            'website' => ['nullable', 'max:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'imię i nazwisko',
            'email' => 'adres e-mail',
            'phone' => 'telefon',
            'items' => 'sprzęt',
            'period' => 'okres wynajmu',
            'city' => 'miejscowość',
            'message' => 'wiadomość',
        ];
    }
}
