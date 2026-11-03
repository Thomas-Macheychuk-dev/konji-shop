<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WithdrawalItemCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'inspection_condition',
        'inspection_hygienic_seal_broken',
        'inspection_signs_of_use',
        'inspection_soiling',
        'inspection_missing_parts',
        'inspection_resellable',
        'inspection_commercial_value_amount',
        'value_reduction_amount',
        'value_reduction_basis',
        'inspected_at',
        'inspected_by_user_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_requested' => 'integer',
            'unit_gross_amount' => 'integer',
            'line_gross_amount' => 'integer',
            'inspection_condition' => WithdrawalItemCondition::class,
            'inspection_hygienic_seal_broken' => 'boolean',
            'inspection_signs_of_use' => 'boolean',
            'inspection_soiling' => 'boolean',
            'inspection_resellable' => 'boolean',
            'inspection_commercial_value_amount' => 'integer',
            'value_reduction_amount' => 'integer',
            'inspected_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(WithdrawalRequestItemPhoto::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function refundableGrossAmount(): int
    {
        return max(0, (int) $this->line_gross_amount - (int) $this->value_reduction_amount);
    }

    public function soldWithHygienicSeal(): bool
    {
        return (bool) data_get($this->orderItem?->meta, 'legal_disclosures.has_hygienic_seal', false);
    }

    public function soldAsCustomMade(): bool
    {
        return (bool) data_get($this->orderItem?->meta, 'legal_disclosures.is_custom_made', false);
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

    public function refundableGrossDecimal(): string
    {
        return $this->formatAmount($this->refundableGrossAmount());
    }

    private function formatAmount(?int $amount): string
    {
        return number_format(((int) $amount) / 100, 2, '.', '');
    }
}
