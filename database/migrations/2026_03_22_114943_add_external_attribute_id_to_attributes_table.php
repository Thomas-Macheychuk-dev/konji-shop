<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table): void {
            $table->string('external_attribute_id')->nullable()->after('slug');
            $table->unique('external_attribute_id');
        });
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table): void {
            $table->dropUnique(['external_attribute_id']);
            $table->dropColumn('external_attribute_id');
        });
    }
};
