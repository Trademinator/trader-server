<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_catalog', function (Blueprint $table) {
            $table->uuid('archive_id')->primary();
            $table->string('logical_type', 64);
            $table->string('exchange', 64)->nullable();
            $table->string('symbol', 64)->nullable();
            $table->string('period', 8)->nullable();
            $table->unsignedBigInteger('range_start_ms');
            $table->unsignedBigInteger('range_end_ms');
            $table->unsignedBigInteger('row_count');
            $table->unsignedSmallInteger('format_version');
            $table->unsignedSmallInteger('schema_version');
            $table->string('compression', 16);
            $table->string('path', 512)->unique();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('compressed_size');
            $table->string('verification_state', 24)->default('pending');
            $table->text('verification_error')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['logical_type', 'exchange', 'symbol', 'period', 'range_start_ms', 'range_end_ms'], 'archive_range_lookup');
            $table->index(['logical_type', 'verification_state']);
        });

        Schema::create('feature_checkpoints', function (Blueprint $table) {
            $table->uuid('checkpoint_id')->primary();
            $table->string('exchange', 64);
            $table->string('symbol', 64);
            $table->string('period', 8);
            $table->string('feature_version', 32);
            $table->unsignedSmallInteger('checkpoint_version');
            $table->unsignedBigInteger('through_ms');
            $table->json('state');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['exchange', 'symbol', 'period', 'feature_version', 'through_ms'], 'feature_checkpoint_identity');
            $table->index(['exchange', 'symbol', 'period', 'feature_version', 'through_ms'], 'feature_checkpoint_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_checkpoints');
        Schema::dropIfExists('archive_catalog');
    }
};
