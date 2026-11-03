<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();

            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('order_number_snapshot')->nullable();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('channel');
            $table->timestamp('received_at');
            $table->date('response_due_on');
            $table->string('product_description', 500);
            $table->text('description');
            $table->string('customer_demand')->nullable();
            $table->string('status');
            $table->timestamp('responded_at')->nullable();
            $table->string('response_channel')->nullable();
            $table->text('response_summary')->nullable();
            $table->text('internal_note')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('received_at');
            $table->index(['responded_at', 'response_due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
