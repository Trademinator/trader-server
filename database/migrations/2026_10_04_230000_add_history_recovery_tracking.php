<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_history_backfills', function (Blueprint $table): void {
            $table->string('recovery_schema', 16)->nullable();
        });
        Schema::create('market_history_changes', function (Blueprint $table): void {
            $table->uuid('change_id')->primary();
            $table->uuid('history_id');
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('from_ms')->nullable();
            $table->unsignedBigInteger('to_ms')->nullable();
            $table->string('source', 32);
            $table->timestamp('created_at', 3);
            $table->unique(['history_id', 'revision']);
            $table->foreign('history_id')->references('history_id')->on('market_history_backfills')->cascadeOnDelete();
        });
        Schema::create('history_recovery_requests', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->uuid('market_id');
            $table->string('period', 16);
            $table->string('schema', 16);
            $table->boolean('retry_unavailable')->default(false);
            $table->string('status', 48)->default('queued');
            $table->text('error')->nullable();
            $table->timestamps(3);
            $table->index(['market_id', 'period', 'created_at']);
            $table->foreign('market_id')->references('market_id')->on('markets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('history_recovery_requests');
        Schema::dropIfExists('market_history_changes');
        Schema::table('market_history_backfills', function (Blueprint $table): void {
            $table->dropColumn('recovery_schema');
        });
    }
};
