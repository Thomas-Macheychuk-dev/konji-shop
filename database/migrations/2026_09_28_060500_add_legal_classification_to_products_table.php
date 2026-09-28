<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('has_hygienic_seal')
                ->default(false)
                ->after('status');

            $table->boolean('is_custom_made')
                ->default(false)
                ->after('has_hygienic_seal');

            $table->boolean('show_compression_measurement_notice')
                ->default(false)
                ->after('is_custom_made');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'has_hygienic_seal',
                'is_custom_made',
                'show_compression_measurement_notice',
            ]);
        });
    }
};
