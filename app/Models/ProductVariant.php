<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'status',
        'price_net_amount',
        'currency',
        'vat_rate',
        'stock_status',
        'is_default',
        'external_variant_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductVariantStatus::class,
            'currency' => Currency::class,
            'vat_rate' => VatRate::class,
            'stock_status' => StockStatus::class,
            'is_default' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'product_variant_attribute_value'
        )->withTimestamps();
    }

    public function grossPriceAmount(): ?int
    {
        if ($this->price_net_amount === null || $this->vat_rate === null) {
            return null;
        }

        return $this->vat_rate->grossFromNet($this->price_net_amount);
    }

    public function vatAmount(): ?int
    {
        if ($this->price_net_amount === null || $this->vat_rate === null) {
            return null;
        }

        return $this->vat_rate->vatAmountFromNet($this->price_net_amount);
    }
}
