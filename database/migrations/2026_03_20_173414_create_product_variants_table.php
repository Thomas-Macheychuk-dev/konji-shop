<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('sku')->unique()->nullable();

            $table->string('status')->default(ProductVariantStatus::DRAFT->value);

            $table->unsignedInteger('price_net_amount')->nullable();
            $table->string('currency', 3)->default(Currency::PLN->value);
            $table->unsignedTinyInteger('vat_rate')->nullable();

            $table->string('stock_status')->default(StockStatus::IN_STOCK->value);
            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id');
            $table->index('status');
            $table->index('stock_status');
            $table->index('is_default');
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
