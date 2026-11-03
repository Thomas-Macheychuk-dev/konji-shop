<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->nullableString($this->input('title')),
            'intro' => $this->nullableString($this->input('intro')),
            'content' => $this->nullableString($this->input('content')),
            'seo_title' => $this->nullableString($this->input('seo_title')),
            'seo_description' => $this->nullableString($this->input('seo_description')),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:200000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'tytuł strony',
            'intro' => 'wstęp',
            'content' => 'treść HTML',
            'seo_title' => 'tytuł SEO',
            'seo_description' => 'opis SEO',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\r\n", "\n", (string) $value));

        return $value === '' ? null : $value;
    }
}
