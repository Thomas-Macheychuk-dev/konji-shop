<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PHOTOS_TABLE = 'withdrawal_request_item_photos';

    // The default foreign key name for this column is 65 characters, over MySQL's 64 limit.
    private const PHOTOS_ITEM_FOREIGN = 'wr_item_photos_item_id_foreign';

    private const PHOTOS_ITEM_INDEX = 'wr_item_photos_item_id_index';

    /**
     * The first MySQL run of this migration failed after adding the item columns and
     * creating the photos table without its foreign keys. MySQL does not roll back DDL,
     * so each step only applies what is still missing.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('withdrawal_request_items', 'inspection_condition')) {
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
        }

        if (! Schema::hasTable(self::PHOTOS_TABLE)) {
            Schema::create(self::PHOTOS_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('withdrawal_request_item_id');
                $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
                $table->string('disk');
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedInteger('size_bytes')->nullable();
                $table->timestamps();
            });
        }

        $this->addMissingPhotoConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::PHOTOS_TABLE);

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

    private function addMissingPhotoConstraints(): void
    {
        $foreignKeyColumns = collect(Schema::getForeignKeys(self::PHOTOS_TABLE))
            ->flatMap(fn (array $foreignKey): array => $foreignKey['columns'])
            ->all();

        $hasItemIndex = Schema::hasIndex(self::PHOTOS_TABLE, self::PHOTOS_ITEM_INDEX);

        Schema::table(self::PHOTOS_TABLE, function (Blueprint $table) use ($foreignKeyColumns, $hasItemIndex): void {
            if (! $hasItemIndex) {
                $table->index('withdrawal_request_item_id', self::PHOTOS_ITEM_INDEX);
            }

            if (! in_array('withdrawal_request_item_id', $foreignKeyColumns, true)) {
                $table->foreign('withdrawal_request_item_id', self::PHOTOS_ITEM_FOREIGN)
                    ->references('id')
                    ->on('withdrawal_request_items')
                    ->cascadeOnDelete();
            }

            if (! in_array('uploaded_by_user_id', $foreignKeyColumns, true)) {
                $table->foreign('uploaded_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }
};
