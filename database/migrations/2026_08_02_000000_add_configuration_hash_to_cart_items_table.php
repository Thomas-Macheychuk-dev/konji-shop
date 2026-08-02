<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CART_ID_INDEX = 'cart_items_cart_id_index';

    public function up(): void
    {
        // MySQL can use the existing (cart_id, product_variant_id) unique
        // index to support the cart_id foreign key. Add a dedicated index first
        // so the legacy unique index can be removed safely.
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->index('cart_id', self::CART_ID_INDEX);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'product_variant_id']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->string('configuration_hash', 64)
                ->default('')
                ->after('product_variant_id');
            $table->unique(
                ['cart_id', 'product_variant_id', 'configuration_hash'],
                'cart_items_variant_configuration_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_variant_configuration_unique');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropColumn('configuration_hash');
            $table->unique(['cart_id', 'product_variant_id']);
        });

        // The restored legacy unique index can support the cart_id foreign key
        // again, so the temporary dedicated index is no longer required.
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropIndex(self::CART_ID_INDEX);
        });
    }
};
