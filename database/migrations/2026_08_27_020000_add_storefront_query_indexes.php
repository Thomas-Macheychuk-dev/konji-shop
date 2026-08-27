<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->index(['parent_id', 'status', 'id'], 'categories_storefront_tree_idx');
            $table->index(['status', 'name', 'id'], 'categories_storefront_nav_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['status', 'name', 'id'], 'products_storefront_listing_idx');
            $table->index(['status', 'published_at', 'id'], 'products_storefront_featured_idx');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->index(
                ['product_id', 'status', 'is_default', 'id'],
                'product_variants_storefront_idx',
            );
        });

        Schema::table('product_images', function (Blueprint $table): void {
            $table->index(['product_id', 'sort_order', 'id'], 'product_images_storefront_idx');
        });

        Schema::table('product_attribute_value_images', function (Blueprint $table): void {
            $table->index(['product_id', 'sort_order', 'id'], 'paiv_storefront_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_attribute_value_images', function (Blueprint $table): void {
            $table->dropIndex('paiv_storefront_idx');
        });

        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropIndex('product_images_storefront_idx');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex('product_variants_storefront_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_storefront_featured_idx');
            $table->dropIndex('products_storefront_listing_idx');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex('categories_storefront_nav_idx');
            $table->dropIndex('categories_storefront_tree_idx');
        });
    }
};
