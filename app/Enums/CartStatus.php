<?php

declare(strict_types=1);

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
    case Expired = 'expired';
}
