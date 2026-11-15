<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The shop rents beds at 200 zł a month; the manual bed goes for 150 zł (owner's feedback, 15 November 2026).
 */
return new class extends Migration
{
    private const PRICES = [
        'lozko-rehabilitacyjne-manualne' => [150, 80],
        'lozko-rehabilitacyjne-elektryczne-2-segmenty' => [200, 100],
        'lozko-rehabilitacyjne-elektryczne-3-funkcyjne' => [200, 125],
        'lozko-rehabilitacyjne-elektryczne-200-kg' => [200, 150],
        'lozko-rehabilitacyjne-elektryczne-4-segmenty' => [200, 150],
    ];

    public function up(): void
    {
        foreach (self::PRICES as $slug => [$monthly]) {
            DB::table('rental_items')->where('slug', $slug)->update(['monthly_min' => $monthly, 'monthly_max' => null, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::PRICES as $slug => [, $previous]) {
            DB::table('rental_items')->where('slug', $slug)->update(['monthly_min' => $previous, 'monthly_max' => null, 'updated_at' => now()]);
        }
    }
};
