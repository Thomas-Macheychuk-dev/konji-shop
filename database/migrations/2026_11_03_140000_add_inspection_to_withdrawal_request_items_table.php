<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_request_items', function (Blueprint $table): void {
            $table->string('inspection_condition')->nullable()->after('line_gross_amount');
            $table->boolean('inspection_hygienic_seal_broken')->nullable()->after('inspection_condition');
            $table->boolean('inspection_signs_of_use')->default(false)->after('inspection_hygienic_seal_broken');
            $table->boolean('inspection_soiling')->default(false)->after('inspection_signs_of_use');
            $table->text('inspection_missing_parts')->nullable()->after('inspection_soiling');
            $table->boolean('inspection_resellable')->nullable()->after('inspection_missing_parts');
            $table->unsignedInteger('inspection_commercial_value_amount')->nullable()->after('inspection_resellable');
            $table->unsignedInteger('value_reduction_amount')->default(0)->after('inspection_commercial_value_amount');
            $table->text('value_reduction_basis')->nullable()->after('value_reduction_amount');
            $table->timestamp('inspected_at')->nullable()->after('value_reduction_basis');

            $table->foreignId('inspected_by_user_id')
                ->nullable()
                ->after('inspected_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('withdrawal_request_item_photos', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('withdrawal_request_item_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('disk');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();

            $table->timestamps();

            $table->index('withdrawal_request_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_request_item_photos');

        Schema::table('withdrawal_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inspected_by_user_id');
            $table->dropColumn([
                'inspection_condition',
                'inspection_hygienic_seal_broken',
                'inspection_signs_of_use',
                'inspection_soiling',
                'inspection_missing_parts',
                'inspection_resellable',
                'inspection_commercial_value_amount',
                'value_reduction_amount',
                'value_reduction_basis',
                'inspected_at',
            ]);
        });
    }
};
