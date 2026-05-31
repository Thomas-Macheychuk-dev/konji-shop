<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->timestamp('refunded_at')->nullable()->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropColumn('refunded_at');
        });
    }
};
