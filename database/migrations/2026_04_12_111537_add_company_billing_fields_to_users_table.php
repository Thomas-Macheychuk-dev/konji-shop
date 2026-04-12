<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('wants_company_invoice')
                ->default(false)
                ->after('phone_number');

            $table->string('company_name')
                ->nullable()
                ->after('wants_company_invoice');

            $table->string('company_tax_id')
                ->nullable()
                ->after('company_name');

            $table->string('company_street')
                ->nullable()
                ->after('company_tax_id');

            $table->string('company_house_number', 50)
                ->nullable()
                ->after('company_street');

            $table->string('company_apartment_number', 50)
                ->nullable()
                ->after('company_house_number');

            $table->string('company_city')
                ->nullable()
                ->after('company_apartment_number');

            $table->string('company_postcode', 50)
                ->nullable()
                ->after('company_city');

            $table->string('company_country', 2)
                ->nullable()
                ->after('company_postcode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'wants_company_invoice',
                'company_name',
                'company_tax_id',
                'company_street',
                'company_house_number',
                'company_apartment_number',
                'company_city',
                'company_postcode',
                'company_country',
            ]);
        });
    }
};
