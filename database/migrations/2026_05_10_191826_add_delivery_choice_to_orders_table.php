<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('delivery_provider')->nullable()->after('fulfilment_status');
            $table->string('delivery_service')->nullable()->after('delivery_provider');
            $table->string('delivery_locker_code')->nullable()->after('delivery_service');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_provider',
                'delivery_service',
                'delivery_locker_code',
            ]);
        });
    }
};
