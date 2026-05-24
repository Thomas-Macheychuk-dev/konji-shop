<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('package_weight_grams')->nullable()->after('stock_status');
            $table->unsignedInteger('package_length_mm')->nullable()->after('package_weight_grams');
            $table->unsignedInteger('package_width_mm')->nullable()->after('package_length_mm');
            $table->unsignedInteger('package_height_mm')->nullable()->after('package_width_mm');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            //
        });
    }
};
