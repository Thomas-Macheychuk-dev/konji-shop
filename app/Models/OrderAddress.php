<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends Model
{
    protected $fillable = [
        'order_id',
        'type',
        'first_name',
        'last_name',
        'company',
        'phone',
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'postcode',
        'country_code',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function formattedLines(): array
    {
        return array_values(array_filter([
            $this->fullName(),
            $this->company,
            $this->address_line_1,
            $this->address_line_2,
            trim($this->postcode . ' ' . $this->city),
            $this->country_code,
        ]));
    }

    public function isShipping(): bool
    {
        return $this->type === 'shipping';
    }

    public function isBilling(): bool
    {
        return $this->type === 'billing';
    }
}
