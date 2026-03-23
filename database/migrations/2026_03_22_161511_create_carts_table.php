<?php

declare(strict_types=1);

use App\Enums\CartStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->uuid('guest_token')->nullable()->index();
            $table->string('status')->default(CartStatus::Active->value)->index();
            $table->string('currency', 3)->default('PLN');

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['guest_token', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
