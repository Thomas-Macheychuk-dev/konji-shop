<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
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

            $table->index('product_id');
            $table->index('sort_order');
            $table->index('is_main');
            $table->index(['product_id', 'is_main']);
            $table->index('sha256');
            $table->index(['product_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
