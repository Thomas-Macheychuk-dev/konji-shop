<?php

declare(strict_types=1);

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('number')->unique();

            $table->string('status')->default(WithdrawalStatus::SUBMITTED->value);

            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('order_number_snapshot');

            $table->text('reason')->nullable();
            $table->text('customer_note')->nullable();
            $table->text('refund_note')->nullable();

            $table->timestamp('submitted_at');
            $table->timestamp('acknowledged_at')->nullable();

            $table->string('submission_ip', 45)->nullable();
            $table->text('submission_user_agent')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('customer_email');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
