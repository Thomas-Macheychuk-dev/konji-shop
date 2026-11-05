<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A small copy of the image for product cards; catalogue:generate-thumbnails fills it.
     */
    public function up(): void
    {
        foreach (['product_images', 'product_attribute_value_images'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('thumbnail_path')->nullable()->after('path');
            });
        }
    }

    public function down(): void
    {
        foreach (['product_images', 'product_attribute_value_images'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('thumbnail_path');
            });
        }
    }
};
