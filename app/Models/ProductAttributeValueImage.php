<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductAttributeValueImage extends Model
{
    protected $fillable = [
        'product_id',
        'attribute_value_id',
        'disk',
        'path',
        'source_url',
        'mime_type',
        'file_size',
        'sha256',
        'alt_text',
        'title',
        'sort_order',
        'is_main',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'sort_order' => 'integer',
            'is_main' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
