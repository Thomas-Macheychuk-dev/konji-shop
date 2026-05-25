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
            $table->string('label_disk')->nullable()->after('payload');
            $table->string('label_path')->nullable()->after('label_disk');
            $table->timestamp('label_downloaded_at')->nullable()->after('label_path');

            $table->index(['label_disk', 'label_path']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['label_disk', 'label_path']);

            $table->dropColumn([
                'label_disk',
                'label_path',
                'label_downloaded_at',
            ]);
        });
    }
};
