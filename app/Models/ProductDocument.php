<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDocument extends Model
{
    protected $fillable = [
        'product_id',
        'external_source',
        'external_id',
        'title',
        'file_name',
        'disk',
        'path',
        'mime_type',
        'file_size',
        'sha256',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
