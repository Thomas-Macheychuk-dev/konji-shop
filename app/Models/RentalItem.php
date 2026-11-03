<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Storage\PublicFilesystemUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RentalItem extends Model
{
    public const BUNDLED_IMAGE_DIRECTORY = 'images/rental/';

    public const UPLOADED_IMAGE_DIRECTORY = 'rental';

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
        'deposit_min',
        'deposit_max',
        'monthly_min',
        'monthly_max',
        'nfz_purchase',
        'image_path',
        'sort_order',
        'is_active',
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
        ];
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
