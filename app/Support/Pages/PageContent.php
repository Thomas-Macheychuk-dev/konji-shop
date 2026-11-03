<?php

declare(strict_types=1);

namespace App\Support\Pages;

use App\Enums\EditablePage;
use App\Models\Page;

/**
 * The texts a storefront page shows: what an admin saved, falling back field by
 * field to the Blade defaults. Defaults are rendered on demand, so contact data
 * from config stays current until an admin overrides the text that holds it.
 */
final readonly class PageContent
{
    private const FIELDS = ['title', 'intro', 'content', 'seo_title', 'seo_description'];

    public function __construct(
        public string $title,
        public string $intro,
        public string $content,
        public ?string $seoTitle,
        public ?string $seoDescription,
    ) {}

    public static function for(EditablePage $page): self
    {
        $stored = Page::query()->where('slug', $page->value)->first();

        return new self(
            title: $stored?->title ?? $page->defaultTitle(),
            intro: $stored?->intro ?? self::render($page->defaultIntroView()),
            content: $stored?->content ?? self::render($page->defaultContentView()),
            seoTitle: $stored?->seo_title ?? $page->defaultSeoTitle(),
            seoDescription: $stored?->seo_description ?? $page->defaultSeoDescription(),
        );
    }

    public static function defaults(EditablePage $page): self
    {
        return new self(
            title: $page->defaultTitle(),
            intro: self::render($page->defaultIntroView()),
            content: self::render($page->defaultContentView()),
            seoTitle: $page->defaultSeoTitle(),
            seoDescription: $page->defaultSeoDescription(),
        );
    }

    /**
     * Stores the submitted texts, keeping null for any field left empty or equal
     * to its default so that the page keeps following the template there.
     *
     * @param  array<string, string|null>  $values
     */
    public static function save(EditablePage $page, array $values): Page
    {
        $defaults = self::defaults($page)->toArray();
        $attributes = [];

        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;

            $attributes[$field] = $value === null || self::normalise($value) === self::normalise((string) $defaults[$field])
                ? null
                : $value;
        }

        return Page::query()->updateOrCreate(['slug' => $page->value], $attributes);
    }

    public static function isCustomised(?Page $stored): bool
    {
        if ($stored === null) {
            return false;
        }

        foreach (self::FIELDS as $field) {
            if ($stored->{$field} !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'intro' => $this->intro,
            'content' => $this->content,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
        ];
    }

    private static function render(string $view): string
    {
        return trim(view($view)->render());
    }

    private static function normalise(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $html));
    }
}
