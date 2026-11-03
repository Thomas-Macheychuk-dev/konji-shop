<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_items', function (Blueprint $table): void {
            // Long HTML description for the item's own page; `details` stays the one-line card summary.
            $table->longText('description')->nullable()->after('details');
            $table->string('seo_title')->nullable()->after('description');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->boolean('is_indexable')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('rental_items', function (Blueprint $table): void {
            $table->dropColumn(['description', 'seo_title', 'seo_description', 'is_indexable']);
        });
    }
};
