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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot')->nullable();

            $table->unsignedInteger('unit_price_amount');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('line_total_amount');

            $table->unsignedTinyInteger('vat_rate_snapshot')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
            $table->index('product_variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
