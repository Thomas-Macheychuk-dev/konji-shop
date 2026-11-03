<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Storage\PublicFilesystemUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RentalItem extends Model
{
    public const BUNDLED_IMAGE_DIRECTORY = 'images/rental/';

    public const UPLOADED_IMAGE_DIRECTORY = 'rental';

    /**
     * Item pages with less description than this stay out of Google's index:
     * a photo, a price and a sentence is thin content that can drag the site down.
     */
    public const MIN_INDEXABLE_WORDS = 150;

    /** Display order and headings of the rental page sections. */
    public const GROUPS = [
        'beds' => 'Łóżka rehabilitacyjne',
        'oxygen' => 'Koncentratory tlenu',
        'transfer' => 'Podnośniki i schodołazy',
        'wheelchairs' => 'Wózki inwalidzkie',
        'mobility' => 'Balkoniki, chodziki i kule',
    ];

    protected $fillable = [
        'slug',
        'group',
        'name',
        'details',
        'description',
        'seo_title',
        'seo_description',
        'deposit_min',
        'deposit_max',
        'monthly_min',
        'monthly_max',
        'nfz_purchase',
        'image_path',
        'sort_order',
        'is_active',
        'is_indexable',
    ];

    protected function casts(): array
    {
        return [
            'deposit_min' => 'integer',
            'deposit_max' => 'integer',
            'monthly_min' => 'integer',
            'monthly_max' => 'integer',
            'nfz_purchase' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_indexable' => 'boolean',
        ];
    }

    public function url(): string
    {
        return route('rental.items.show', $this);
    }

    public function groupHeading(): string
    {
        return self::GROUPS[$this->group] ?? '';
    }

    public function descriptionWordCount(): int
    {
        $text = html_entity_decode(strip_tags((string) $this->description), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (int) preg_match_all('/[\p{L}\p{N}]+/u', $text);
    }

    /** Whether the item page is offered to search engines (robots meta and sitemap). */
    public function shouldBeIndexed(): bool
    {
        return $this->is_active
            && $this->is_indexable
            && $this->descriptionWordCount() >= self::MIN_INDEXABLE_WORDS;
    }

    public function seoTitle(): string
    {
        return filled($this->seo_title)
            ? $this->seo_title
            : $this->name.' – wypożyczalnia Poznań';
    }

    public function seoDescription(): string
    {
        if (filled($this->seo_description)) {
            return $this->seo_description;
        }

        $parts = array_filter([
            'Wypożycz: '.$this->name.' w Poznaniu.',
            $this->details,
            'Opłata miesięczna '.$this->monthlyLabel().', kaucja: '.mb_strtolower($this->depositLabel()).'.',
            'Dostawa i przygotowanie do użycia.',
        ]);

        return Str::limit(implode(' ', $parts), 160, '', preserveWords: true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Seeded images ship in public/images/rental; images uploaded in the admin
     * live on the public disk.
     */
    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        return $this->hasBundledImage()
            ? asset($this->image_path)
            : PublicFilesystemUrl::url($this->image_path);
    }

    public function hasBundledImage(): bool
    {
        return str_starts_with((string) $this->image_path, self::BUNDLED_IMAGE_DIRECTORY);
    }

    public function depositLabel(): string
    {
        return $this->deposit_min === null ? 'Bez kaucji' : self::priceRange($this->deposit_min, $this->deposit_max);
    }

    public function monthlyLabel(): string
    {
        return self::priceRange($this->monthly_min, $this->monthly_max);
    }

    private static function priceRange(int $min, ?int $max): string
    {
        return $max !== null && $max > $min
            ? sprintf('%d–%d zł', $min, $max)
            : sprintf('%d zł', $min);
    }
}
