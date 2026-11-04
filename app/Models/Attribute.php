<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeDisplayType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Attribute extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'external_attribute_id',
        'display_type',
    ];

    protected function casts(): array
    {
        return [
            'display_type' => AttributeDisplayType::class,
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    /**
     * Shopper-facing label from config/storefront_labels.php, if one is set for this attribute.
     * Importers keep matching on the supplier name, so it is never renamed in the database.
     */
    public function storefrontLabel(): ?string
    {
        $label = config('storefront_labels.attributes', [])[$this->slug] ?? null;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        foreach (config('storefront_labels.attribute_name_patterns', []) as $pattern => $patternLabel) {
            if (preg_match($pattern, (string) $this->name) === 1) {
                return $patternLabel;
            }
        }

        return null;
    }

    /**
     * Name shown to shoppers: the configured label, else the supplier name without leading "*" markers.
     */
    public function storefrontName(): string
    {
        $cleaned = Str::ucfirst(trim(ltrim(trim((string) $this->name), '*')));

        return $this->storefrontLabel() ?? ($cleaned !== '' ? $cleaned : (string) $this->name);
    }
}
