<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CategoryStatus;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->nullableString($this->input('name'));
        $slug = $this->nullableString($this->input('slug'));

        $this->merge([
            'parent_id' => $this->nullableInteger($this->input('parent_id')),
            'name' => $name,
            'slug' => $this->normalizeSlug($slug ?: $name),
            'description' => $this->nullableString($this->input('description')),
            'seo_title' => $this->nullableString($this->input('seo_title')),
            'seo_description' => $this->nullableString($this->input('seo_description')),
        ]);
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $categoryId = $category instanceof Category ? $category->id : null;

        return [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
                Rule::notIn(array_filter([$categoryId])),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->ignore($categoryId),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(CategoryStatus::options())],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $category = $this->route('category');
            $parentId = $this->input('parent_id');

            if (! $category instanceof Category || $parentId === null) {
                return;
            }

            $currentParentId = (int) $parentId;

            while ($currentParentId > 0) {
                if ($currentParentId === $category->id) {
                    $validator->errors()->add(
                        'parent_id',
                        'Kategoria nie może być przypisana do własnej podkategorii.'
                    );

                    return;
                }

                $parent = Category::query()->find($currentParentId);

                if ($parent === null || $parent->parent_id === null) {
                    return;
                }

                $currentParentId = (int) $parent->parent_id;
            }
        });
    }

    public function attributes(): array
    {
        return [
            'parent_id' => 'kategoria nadrzędna',
            'name' => 'nazwa kategorii',
            'slug' => 'slug',
            'description' => 'opis kategorii',
            'status' => 'status kategorii',
            'seo_title' => 'tytuł SEO',
            'seo_description' => 'opis SEO',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeSlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $slug = Str::slug($value);

        return $slug === '' ? null : $slug;
    }
}
