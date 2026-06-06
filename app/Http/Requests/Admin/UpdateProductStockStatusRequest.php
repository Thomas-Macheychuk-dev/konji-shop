<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\StockStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductStockStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'stock_status' => ['required', 'string', Rule::in(StockStatus::options())],
        ];
    }

    public function attributes(): array
    {
        return [
            'stock_status' => __('Stock status'),
        ];
    }
}
