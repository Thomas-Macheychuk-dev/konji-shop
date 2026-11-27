<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attributes with a filter_position are offered as listing filters ("Długość fryzury", "Kolor włosów"…), in that
 * order, wherever the listed products have values. The manufacturer keeps its own "marka" filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('filter_position')->nullable()->after('display_type');
            $table->index('filter_position');
        });
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table): void {
            $table->dropIndex(['filter_position']);
            $table->dropColumn('filter_position');
        });
    }
};
