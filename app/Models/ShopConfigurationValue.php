<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ShopConfigurationValue extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];
}
