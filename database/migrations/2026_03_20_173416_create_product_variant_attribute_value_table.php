<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_attribute_value', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_variant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('attribute_value_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['product_variant_id', 'attribute_value_id'],
                'pvav_variant_value_unique'
            );

            $table->index('product_variant_id', 'pvav_variant_idx');
            $table->index('attribute_value_id', 'pvav_attr_value_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_value');
    }
};
