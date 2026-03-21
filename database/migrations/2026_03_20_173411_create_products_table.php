<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();

            $table->string('status')->default(ProductStatus::DRAFT->value);

            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('status');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
