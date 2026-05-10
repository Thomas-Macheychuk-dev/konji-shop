<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider');
            $table->string('provider_reference')->nullable();

            $table->string('status')->default('pending');

            $table->string('tracking_number')->nullable();
            $table->string('tracking_url')->nullable();

            $table->string('service')->nullable();
            $table->string('locker_code')->nullable();

            $table->json('payload')->nullable();

            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('provider');
            $table->index('provider_reference');
            $table->index('status');
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
