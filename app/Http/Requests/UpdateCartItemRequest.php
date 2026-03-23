<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cart\CartLimits;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => [
                'required',
                'integer',
                'min:'.CartLimits::MIN_QUANTITY_PER_LINE,
                'max:'.CartLimits::MAX_QUANTITY_PER_LINE,
            ],
        ];
    }
}
