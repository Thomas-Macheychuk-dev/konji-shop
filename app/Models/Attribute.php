<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeDisplayType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    protected $fillable = [
        'name',
        'slug',
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
}
