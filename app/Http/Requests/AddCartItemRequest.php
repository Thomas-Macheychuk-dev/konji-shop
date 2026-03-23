<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cart\CartLimits;
use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => [
                'required',
                'integer',
                'min:'.CartLimits::MIN_QUANTITY_PER_LINE,
                'max:'.CartLimits::MAX_QUANTITY_PER_LINE,
            ],
        ];
    }
}
