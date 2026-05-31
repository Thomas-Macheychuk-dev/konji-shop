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
            $table->string('default_image_type')->nullable()->after('external_parent_sku');
            $table->unsignedBigInteger('default_image_id')->nullable()->after('default_image_type');

            $table->index(['default_image_type', 'default_image_id'], 'products_default_image_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_default_image_idx');
            $table->dropColumn([
                'default_image_type',
                'default_image_id',
            ]);
        });
    }
};
