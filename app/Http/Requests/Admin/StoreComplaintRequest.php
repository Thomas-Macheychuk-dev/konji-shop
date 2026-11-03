<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ComplaintChannel;
use App\Enums\ComplaintDemand;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'channel' => ['required', Rule::enum(ComplaintChannel::class)],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'order_number' => ['nullable', 'string', 'max:255'],
            'product_description' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:10000'],
            'customer_demand' => ['nullable', Rule::enum(ComplaintDemand::class)],
            'internal_note' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function complaintAttributes(): array
    {
        $orderNumber = trim((string) $this->validated('order_number'));
        $order = $orderNumber === ''
            ? null
            : Order::query()->where('number', $orderNumber)->first();

        return [
            'received_at' => Carbon::parse((string) $this->validated('received_at')),
            'channel' => $this->validated('channel'),
            'customer_name' => trim((string) $this->validated('customer_name')),
            'customer_email' => $this->nullableString('customer_email'),
            'customer_phone' => $this->nullableString('customer_phone'),
            // A wrong or missing order number is not a reason to refuse a complaint, so it is kept as typed.
            'order_id' => $order?->id,
            'order_number_snapshot' => $orderNumber === '' ? null : $orderNumber,
            'product_description' => trim((string) $this->validated('product_description')),
            'description' => trim((string) $this->validated('description')),
            'customer_demand' => $this->validated('customer_demand') ?: null,
            'internal_note' => $this->nullableString('internal_note'),
        ];
    }

    public function attributes(): array
    {
        return [
            'received_at' => 'data otrzymania reklamacji',
            'channel' => 'sposób zgłoszenia',
            'customer_name' => 'imię i nazwisko klienta',
            'customer_email' => 'e-mail klienta',
            'customer_phone' => 'telefon klienta',
            'order_number' => 'numer zamówienia',
            'product_description' => 'towar',
            'description' => 'opis problemu',
            'customer_demand' => 'żądanie klienta',
            'internal_note' => 'notatka wewnętrzna',
        ];
    }

    protected function nullableString(string $key): ?string
    {
        $value = trim((string) $this->validated($key));

        return $value === '' ? null : $value;
    }
}
