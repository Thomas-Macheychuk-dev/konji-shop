<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EditablePage;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-edited texts of an {@see EditablePage}. A null column means
 * "use the default from the Blade template".
 */
class Page extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'intro',
        'content',
        'seo_title',
        'seo_description',
    ];
}
