<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductDefaultImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'default_image' => [
                'required',
                'string',
                Rule::in($this->allowedSelections()),
            ],
        ];
    }

    public function defaultImageType(): string
    {
        return $this->selectionParts()[0];
    }

    public function defaultImageId(): int
    {
        return (int) $this->selectionParts()[1];
    }

    public function attributes(): array
    {
        return [
            'default_image' => __('Default image'),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedSelections(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        if (! $product instanceof Product) {
            return [];
        }

        $product->loadMissing([
            'images',
            'attributeValueImages',
        ]);

        return $product->images
            ->map(fn ($image): string => Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE.':'.$image->id)
            ->merge(
                $product->attributeValueImages
                    ->map(fn ($image): string => Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE.':'.$image->id)
            )
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function selectionParts(): array
    {
        $parts = explode(':', (string) $this->validated('default_image'), 2);

        return [
            $parts[0] ?? '',
            $parts[1] ?? '0',
        ];
    }
}
