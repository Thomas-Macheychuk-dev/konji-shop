<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'unit_net_amount',
        'unit_tax_amount',
        'unit_gross_amount',

        'quantity',

        'line_total_amount',
        'line_net_amount',
        'line_tax_amount',
        'line_gross_amount',

        'vat_rate_snapshot',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_amount' => 'integer',
            'unit_net_amount' => 'integer',
            'unit_tax_amount' => 'integer',
            'unit_gross_amount' => 'integer',

            'quantity' => 'integer',

            'line_total_amount' => 'integer',
            'line_net_amount' => 'integer',
            'line_tax_amount' => 'integer',
            'line_gross_amount' => 'integer',

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

    public function withdrawalRequestItems(): HasMany
    {
        return $this->hasMany(WithdrawalRequestItem::class);
    }

    public function unitPriceDecimal(): string
    {
        return $this->formatAmount($this->unit_price_amount);
    }

    public function unitNetDecimal(): string
    {
        return $this->formatAmount($this->unit_net_amount);
    }

    public function unitTaxDecimal(): string
    {
        return $this->formatAmount($this->unit_tax_amount);
    }

    public function unitGrossDecimal(): string
    {
        return $this->formatAmount($this->unit_gross_amount ?: $this->unit_price_amount);
    }

    public function lineTotalDecimal(): string
    {
        return $this->formatAmount($this->line_total_amount);
    }

    public function lineNetDecimal(): string
    {
        return $this->formatAmount($this->line_net_amount);
    }

    public function lineTaxDecimal(): string
    {
        return $this->formatAmount($this->line_tax_amount);
    }

    public function lineGrossDecimal(): string
    {
        return $this->formatAmount($this->line_gross_amount ?: $this->line_total_amount);
    }

    public function vatRateLabel(): string
    {
        return $this->vat_rate_snapshot !== null
            ? $this->vat_rate_snapshot.'%'
            : '—';
    }

    public function hasTaxBreakdown(): bool
    {
        return $this->unit_net_amount > 0
            || $this->unit_tax_amount > 0
            || $this->line_net_amount > 0
            || $this->line_tax_amount > 0;
    }

    private function formatAmount(?int $amount): string
    {
        return number_format(((int) $amount) / 100, 2, '.', '');
    }
}
