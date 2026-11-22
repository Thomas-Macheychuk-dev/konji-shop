<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flat gross delivery price in grosze for products too large for a parcel (Corradomed couches, tables,
 * screens). A cart with such a product is delivered by courier at the highest of these prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('large_item_delivery_amount')->nullable()->after('colour_label');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('large_item_delivery_amount');
        });
    }
};
