<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'sku_snapshot',
        'unit_price_amount',
        'quantity',
        'line_total_amount',
        'vat_rate_snapshot',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_amount' => 'integer',
            'quantity' => 'integer',
            'line_total_amount' => 'integer',
            'vat_rate_snapshot' => 'integer',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unitPriceDecimal(): string
    {
        return number_format($this->unit_price_amount / 100, 2, '.', '');
    }

    public function lineTotalDecimal(): string
    {
        return number_format($this->line_total_amount / 100, 2, '.', '');
    }
}
