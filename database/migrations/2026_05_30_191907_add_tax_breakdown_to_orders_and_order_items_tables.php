<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('items_net_amount')->default(0)->after('subtotal_amount');
            $table->unsignedInteger('items_tax_amount')->default(0)->after('items_net_amount');
            $table->unsignedInteger('items_gross_amount')->default(0)->after('items_tax_amount');

            $table->unsignedInteger('shipping_net_amount')->default(0)->after('shipping_amount');
            $table->unsignedInteger('shipping_tax_amount')->default(0)->after('shipping_net_amount');
            $table->unsignedInteger('shipping_gross_amount')->default(0)->after('shipping_tax_amount');

            $table->unsignedInteger('tax_amount')->default(0)->after('discount_amount');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('unit_net_amount')->default(0)->after('unit_price_amount');
            $table->unsignedInteger('unit_tax_amount')->default(0)->after('unit_net_amount');
            $table->unsignedInteger('unit_gross_amount')->default(0)->after('unit_tax_amount');

            $table->unsignedInteger('line_net_amount')->default(0)->after('line_total_amount');
            $table->unsignedInteger('line_tax_amount')->default(0)->after('line_net_amount');
            $table->unsignedInteger('line_gross_amount')->default(0)->after('line_tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn([
                'unit_net_amount',
                'unit_tax_amount',
                'unit_gross_amount',
                'line_net_amount',
                'line_tax_amount',
                'line_gross_amount',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'items_net_amount',
                'items_tax_amount',
                'items_gross_amount',
                'shipping_net_amount',
                'shipping_tax_amount',
                'shipping_gross_amount',
                'tax_amount',
            ]);
        });
    }
};
