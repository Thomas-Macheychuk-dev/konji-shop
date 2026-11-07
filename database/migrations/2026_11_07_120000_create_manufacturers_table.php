<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GPSR (EU 2023/988) manufacturer details, one row per "Producent"/"Marka" attribute value,
        // so every product carrying that brand shows them.
        Schema::create('manufacturers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_value_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('address');
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('website')->nullable();
            // Required when the manufacturer is established outside the EU.
            $table->string('eu_representative_name')->nullable();
            $table->text('eu_representative_address')->nullable();
            $table->string('eu_representative_email')->nullable();
            $table->text('safety_information')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturers');
    }
};
