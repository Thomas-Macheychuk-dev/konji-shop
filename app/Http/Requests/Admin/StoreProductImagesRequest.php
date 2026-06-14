<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'product_images' => ['required', 'array', 'min:1', 'max:10'],
            'product_images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_images' => 'zdjęcia produktu',
            'product_images.*' => 'zdjęcie produktu',
        ];
    }
}
