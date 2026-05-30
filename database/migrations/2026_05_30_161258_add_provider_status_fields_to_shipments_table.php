<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('provider_status_code', 32)
                ->nullable()
                ->after('tracking_url')
                ->index();

            $table->string('provider_status_label')
                ->nullable()
                ->after('provider_status_code');

            $table->timestamp('provider_status_updated_at')
                ->nullable()
                ->after('provider_status_label');

            $table->timestamp('provider_delivered_at')
                ->nullable()
                ->after('provider_status_updated_at');

            $table->timestamp('status_synced_at')
                ->nullable()
                ->after('provider_delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['provider_status_code']);

            $table->dropColumn([
                'provider_status_code',
                'provider_status_label',
                'provider_status_updated_at',
                'provider_delivered_at',
                'status_synced_at',
            ]);
        });
    }
};
