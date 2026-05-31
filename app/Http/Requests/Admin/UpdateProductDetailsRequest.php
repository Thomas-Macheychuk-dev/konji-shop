<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(ProductStatus::options())],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('Product name'),
            'status' => __('Product status'),
            'category_id' => __('Product category'),
        ];
    }
}
