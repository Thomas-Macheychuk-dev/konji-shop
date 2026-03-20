<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('attribute_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('value');
            $table->string('slug');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['attribute_id', 'slug']);
            $table->index('attribute_id');
            $table->index('value');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};
