<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_value', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('attribute_value_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['product_id', 'attribute_value_id'],
                'product_attribute_value_unique'
            );

            $table->index('product_id', 'product_attribute_value_product_idx');
            $table->index('attribute_value_id', 'product_attribute_value_value_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_value');
    }
};
