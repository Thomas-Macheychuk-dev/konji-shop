<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_variants', 'price_gross_amount')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('price_gross_amount')
                ->nullable()
                ->after('price_net_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_variants', 'price_gross_amount')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('price_gross_amount');
        });
    }
};
