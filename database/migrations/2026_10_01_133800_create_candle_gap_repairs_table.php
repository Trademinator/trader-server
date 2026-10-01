<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candle_gap_repairs', function (Blueprint $table) {
            $table->collation = 'utf8mb4_bin';
            $table->uuid('gap_id')->primary();
            $table->uuid('market_id');
            $table->string('period', 4);
            $table->unsignedBigInteger('from_ms');
            $table->unsignedBigInteger('to_ms');
            $table->string('status', 24)->default('pending');
            $table->string('reason', 100)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('empty_attempts')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamps();
            $table->unique(['market_id', 'period', 'from_ms', 'to_ms']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['market_id', 'period', 'status']);
            $table->foreign('market_id')->references('market_id')->on('markets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candle_gap_repairs');
    }
};
