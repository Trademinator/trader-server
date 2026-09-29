<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_history_backfills', function (Blueprint $table) {
            $table->collation = 'utf8mb4_bin';
            $table->uuid('history_id')->primary();
            $table->uuid('market_id');
            $table->string('period', 4);
            $table->string('status', 24)->default('pending');
            $table->string('reason', 100)->nullable();
            $table->unsignedBigInteger('before_ms')->nullable();
            $table->unsignedBigInteger('oldest_candle_ms')->nullable();
            $table->unsignedBigInteger('window_start_ms')->nullable();
            $table->unsignedBigInteger('window_end_ms')->nullable();
            $table->unsignedBigInteger('next_since_ms')->nullable();
            $table->unsignedInteger('window_rows')->default(0);
            $table->unsignedBigInteger('candles_received')->default(0);
            $table->unsignedInteger('empty_windows')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->unsignedBigInteger('history_revision')->default(0);
            $table->unsignedBigInteger('trained_revision')->default(0);
            $table->unsignedBigInteger('build_revision')->nullable();
            $table->string('build_stage', 16)->nullable();
            $table->uuid('build_lease_token')->nullable();
            $table->timestamp('build_lease_until')->nullable();
            $table->timestamp('build_next_attempt_at')->nullable();
            $table->unsignedInteger('build_failures')->default(0);
            $table->text('build_error')->nullable();
            $table->uuid('model_id')->nullable();
            $table->timestamp('last_trained_at')->nullable();
            $table->timestamps();
            $table->unique(['market_id', 'period']);
            $table->index(['status', 'next_attempt_at']);
            $table->foreign('market_id')->references('market_id')->on('markets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_history_backfills');
    }
};
