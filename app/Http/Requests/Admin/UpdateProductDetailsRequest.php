<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProductDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'has_hygienic_seal' => $this->boolean('has_hygienic_seal'),
            'is_custom_made' => $this->boolean('is_custom_made'),
            'show_compression_measurement_notice' => $this->boolean('show_compression_measurement_notice'),
        ]);
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
            'has_hygienic_seal' => ['required', 'boolean'],
            'is_custom_made' => ['required', 'boolean'],
            'show_compression_measurement_notice' => ['required', 'boolean'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('show_compression_measurement_notice') && ! $this->boolean('has_hygienic_seal')) {
                $validator->errors()->add(
                    'show_compression_measurement_notice',
                    'Informację o doborze rozmiaru i kompresji można włączyć tylko razem z opcją „Zabezpieczenie higieniczne: TAK”.'
                );
            }
        });
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
            'has_hygienic_seal' => 'zabezpieczenie higieniczne',
            'is_custom_made' => 'towar indywidualny',
            'show_compression_measurement_notice' => 'informacja o doborze rozmiaru i kompresji',
            'category_id' => __('Product category'),
        ];
    }
}
