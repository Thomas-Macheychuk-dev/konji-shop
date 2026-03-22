<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_value_images', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('attribute_value_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('disk')->default('public');
            $table->string('path');
            $table->text('source_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();

            $table->string('alt_text')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_main')->default(false);

            $table->timestamps();

            $table->unique(['product_id', 'attribute_value_id', 'path'], 'paiv_unique');
            $table->index(['product_id', 'attribute_value_id'], 'paiv_product_value_idx');
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_value_images');
    }
};
