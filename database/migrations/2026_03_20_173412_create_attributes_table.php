<?php

declare(strict_types=1);

use App\Enums\AttributeDisplayType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('external_attribute_id')->nullable()->unique();
            $table->string('display_type')->default(AttributeDisplayType::SELECT->value);

            $table->timestamps();

            $table->index('name');
            $table->index('display_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};
