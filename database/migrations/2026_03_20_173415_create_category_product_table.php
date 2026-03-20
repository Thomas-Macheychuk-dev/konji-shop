<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->unique(['category_id', 'product_id']);
            $table->index('category_id');
            $table->index('product_id');
            $table->index('is_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
