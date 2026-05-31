<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_request_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('withdrawal_request_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('order_item_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot')->nullable();

            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_requested');

            $table->unsignedInteger('unit_gross_amount')->default(0);
            $table->unsignedInteger('line_gross_amount')->default(0);

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index('withdrawal_request_id');
            $table->index('order_item_id');
            $table->index('product_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_request_items');
    }
};
