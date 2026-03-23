<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();

            $table->string('status')->default('unpaid');

            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PLN');

            $table->timestamp('paid_at')->nullable();

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
            $table->index('provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
