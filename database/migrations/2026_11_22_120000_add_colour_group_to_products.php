<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products sold as one model in several colours, each colour its own product (peruka.pl wigs). Products with
 * the same colour_group are shown on each other's pages as "Wybierz kolor", labelled with colour_label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('colour_group', 64)->nullable()->after('external_parent_sku');
            $table->string('colour_label', 100)->nullable()->after('colour_group');
            $table->index('colour_group');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['colour_group']);
            $table->dropColumn(['colour_group', 'colour_label']);
        });
    }
};
