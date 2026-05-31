<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequestItem extends Model
{
    protected $fillable = [
        'withdrawal_request_id',
        'order_item_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'sku_snapshot',
        'quantity_ordered',
        'quantity_requested',
        'unit_gross_amount',
        'line_gross_amount',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_requested' => 'integer',
            'unit_gross_amount' => 'integer',
            'line_gross_amount' => 'integer',
            'meta' => 'array',
        ];
    }

    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unitGrossDecimal(): string
    {
        return $this->formatAmount($this->unit_gross_amount);
    }

    public function lineGrossDecimal(): string
    {
        return $this->formatAmount($this->line_gross_amount);
    }

    private function formatAmount(?int $amount): string
    {
        return number_format(((int) $amount) / 100, 2, '.', '');
    }
}
