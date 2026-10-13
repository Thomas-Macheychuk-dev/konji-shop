<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('external_source', 64);
            $table->string('external_id', 191);
            $table->string('title');
            $table->string('file_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->unique(['external_source', 'external_id'], 'product_docs_source_external_unique');
            $table->index(['product_id', 'id']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
