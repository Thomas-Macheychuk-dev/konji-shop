<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('external_variant_id')->nullable()->after('product_id');

            $table->unique(
                ['product_id', 'external_variant_id'],
                'product_variants_product_id_external_variant_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('product_variants_product_id_external_variant_id_unique');
            $table->dropColumn('external_variant_id');
        });
    }
};
