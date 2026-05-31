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
            'short_description' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:5000'],
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
            'short_description' => __('Short description'),
            'description' => __('Product HTML description'),
            'seo_title' => __('SEO title'),
            'seo_description' => __('SEO description'),
            'status' => __('Product status'),
            'category_id' => __('Product category'),
        ];
    }
}
