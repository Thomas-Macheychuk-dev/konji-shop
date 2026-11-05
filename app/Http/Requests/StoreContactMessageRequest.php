<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
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
            'order_number' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
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
            'order_number' => 'numer zamówienia',
            'message' => 'wiadomość',
        ];
    }
}
