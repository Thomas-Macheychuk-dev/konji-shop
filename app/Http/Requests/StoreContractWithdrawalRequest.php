<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreContractWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'statement_confirmed' => $this->boolean('statement_confirmed'),
            'submission_ip' => $this->ip(),
            'submission_user_agent' => $this->userAgent()
                ? mb_substr((string) $this->userAgent(), 0, 1000)
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'string', 'email:rfc,dns', 'max:255'],

            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['nullable', 'integer', 'min:0'],

            'reason' => ['nullable', 'string', 'max:2000'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'refund_note' => ['nullable', 'string', 'max:2000'],

            'statement_confirmed' => ['accepted'],

            'submission_ip' => ['nullable', 'ip'],
            'submission_user_agent' => ['nullable', 'string', 'max:1000'],
            'source' => ['required', 'string', 'in:account,guest'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => __('Select at least one order item.'),
            'items.min' => __('Select at least one order item.'),
            'statement_confirmed.accepted' => __('You must confirm that you want to withdraw from the contract.'),
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_name' => __('Customer name'),
            'customer_email' => __('Confirmation email'),
            'items' => __('Items'),
            'reason' => __('Reason'),
            'customer_note' => __('Customer note'),
            'refund_note' => __('Refund note'),
            'statement_confirmed' => __('Withdrawal confirmation'),
        ];
    }
}
