<?php

declare(strict_types=1);

use App\Enums\CategoryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('status')->default(CategoryStatus::ACTIVE->value);

            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
