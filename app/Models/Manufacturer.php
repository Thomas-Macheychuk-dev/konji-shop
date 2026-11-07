<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manufacturer contact details required by the GPSR on every product listing,
 * attached to a "Producent"/"Marka" attribute value.
 */
class Manufacturer extends Model
{
    protected $fillable = [
        'attribute_value_id',
        'name',
        'address',
        'email',
        'phone',
        'website',
        'eu_representative_name',
        'eu_representative_address',
        'eu_representative_email',
        'safety_information',
    ];

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class);
    }

    public function hasEuRepresentative(): bool
    {
        return filled($this->eu_representative_name);
    }
}
