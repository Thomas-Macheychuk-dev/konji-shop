<?php

use App\Enums\PaymentRefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_refund_id')->nullable();
            $table->string('status')->default(PaymentRefundStatus::REQUESTED->value);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PLN');
            $table->string('reason')->nullable();
            $table->string('idempotency_key', 45)->unique();
            $table->json('withdrawal_request_ids');
            $table->json('withdrawal_statuses')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
            $table->index(['payment_id', 'status']);
            $table->index('provider_refund_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
