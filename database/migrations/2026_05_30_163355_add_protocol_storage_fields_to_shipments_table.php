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
            $table->string('protocol_disk')->nullable()->after('label_downloaded_at');
            $table->string('protocol_path')->nullable()->after('protocol_disk');
            $table->timestamp('protocol_downloaded_at')->nullable()->after('protocol_path');

            $table->index(['protocol_disk', 'protocol_path']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['protocol_disk', 'protocol_path']);

            $table->dropColumn([
                'protocol_disk',
                'protocol_path',
                'protocol_downloaded_at',
            ]);
        });
    }
};
