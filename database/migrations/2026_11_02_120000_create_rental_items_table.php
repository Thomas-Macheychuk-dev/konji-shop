<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_items', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('group', 32)->index();
            $table->string('name');
            $table->text('details')->nullable();
            // Whole złoty. A null deposit means no deposit; min < max means the price depends on the model.
            $table->unsignedInteger('deposit_min')->nullable();
            $table->unsignedInteger('deposit_max')->nullable();
            $table->unsignedInteger('monthly_min');
            $table->unsignedInteger('monthly_max')->nullable();
            $table->boolean('nfz_purchase')->default(false);
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_items');
    }
};
