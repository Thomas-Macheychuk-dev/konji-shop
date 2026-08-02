<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\AttributeDisplayType;
use App\Models\AttributeValue;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VermeirenColorSelectionService
{
    /**
     * @param  array<string, mixed>  $submitted
     * @return list<array{
     *     group_code: string,
     *     group_label: string,
     *     value_id: int,
     *     value_label: string
     * }>
     */
    public function resolve(Product $product, array $submitted): array
    {
        if ($product->external_source !== 'vermeiren') {
            return [];
        }

        $groups = $this->groups($product);

        if ($groups->isEmpty()) {
            return [];
        }

        $errors = [];
        $selection = [];

        foreach ($groups as $group) {
            /** @var AttributeValue|null $first */
            $first = $group->first();
            $attribute = $first?->attribute;
            $groupCode = Str::after(
                (string) $attribute?->external_attribute_id,
                'vermeiren-color-',
            );
            $groupLabel = $attribute?->name ?: 'Kolor';
            $selectedValueId = filter_var(
                $submitted[$groupCode] ?? null,
                FILTER_VALIDATE_INT,
            );

            if ($selectedValueId === false || $selectedValueId === null) {
                $errors["informational_colors.{$groupCode}"] = "Wybierz: {$groupLabel}.";

                continue;
            }

            /** @var AttributeValue|null $selectedValue */
            $selectedValue = $group->firstWhere('id', $selectedValueId);

            if ($selectedValue === null) {
                $errors["informational_colors.{$groupCode}"] = "Wybrany wariant pola {$groupLabel} jest niedostępny.";

                continue;
            }

            $selection[] = [
                'group_code' => $groupCode,
                'group_label' => $groupLabel,
                'value_id' => (int) $selectedValue->id,
                'value_label' => (string) $selectedValue->value,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $selection;
    }

    /**
     * @param  list<array{
     *     group_code: string,
     *     group_label: string,
     *     value_id: int,
     *     value_label: string
     * }>  $selection
     */
    public function hash(array $selection): string
    {
        if ($selection === []) {
            return '';
        }

        $canonical = collect($selection)
            ->map(fn (array $item): array => [
                'group_code' => (string) $item['group_code'],
                'value_id' => (int) $item['value_id'],
            ])
            ->sortBy('group_code')
            ->values()
            ->all();

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @return Collection<int, Collection<int, AttributeValue>>
     */
    private function groups(Product $product): Collection
    {
        if (! $product->relationLoaded('attributeValues')) {
            $product->load('attributeValues.attribute');
        }

        return $product->attributeValues
            ->filter(function (AttributeValue $value): bool {
                $attribute = $value->attribute;

                return $attribute?->display_type === AttributeDisplayType::COLOR_SWATCH
                    && Str::startsWith(
                        (string) $attribute->external_attribute_id,
                        'vermeiren-color-',
                    );
            })
            ->groupBy('attribute_id')
            ->sortBy(function (Collection $values): int {
                /** @var AttributeValue|null $first */
                $first = $values->first();
                $code = Str::after(
                    (string) $first?->attribute?->external_attribute_id,
                    'vermeiren-color-',
                );

                return match ($code) {
                    'upholstery' => 1,
                    'frame' => 2,
                    default => 99,
                };
            });
    }
}
