<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProductPackageDimensionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'package_weight_grams' => ['required', 'integer', 'min:1', 'max:100000'],
            'package_length_mm' => ['required', 'integer', 'min:1', 'max:5000'],
            'package_width_mm' => ['required', 'integer', 'min:1', 'max:5000'],
            'package_height_mm' => ['required', 'integer', 'min:1', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'package_weight_grams' => __('Package weight'),
            'package_length_mm' => __('Package length'),
            'package_width_mm' => __('Package width'),
            'package_height_mm' => __('Package height'),
        ];
    }
}
