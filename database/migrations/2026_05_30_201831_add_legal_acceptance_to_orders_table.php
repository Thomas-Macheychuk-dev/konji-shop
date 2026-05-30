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
            $table->timestamp('terms_accepted_at')->nullable()->after('placed_at');
            $table->string('terms_version')->nullable()->after('terms_accepted_at');
            $table->string('privacy_version')->nullable()->after('terms_version');
            $table->string('returns_policy_version')->nullable()->after('privacy_version');

            $table->string('legal_acceptance_ip', 45)->nullable()->after('returns_policy_version');
            $table->text('legal_acceptance_user_agent')->nullable()->after('legal_acceptance_ip');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'terms_accepted_at',
                'terms_version',
                'privacy_version',
                'returns_policy_version',
                'legal_acceptance_ip',
                'legal_acceptance_user_agent',
            ]);
        });
    }
};
