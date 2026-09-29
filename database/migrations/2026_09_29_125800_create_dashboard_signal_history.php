<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_signals', function (Blueprint $table) {
            $table->uuid('market_signal_id')->primary();
            $table->uuid('market_id');
            $table->foreign('market_id')->references('market_id')->on('markets')->cascadeOnDelete();
            $table->char('snapshot_key', 64)->unique();
            $table->string('period', 4);
            $table->uuid('model_id')->nullable();
            $table->unsignedBigInteger('decision_at_ms')->nullable();
            $table->unsignedBigInteger('recorded_at_ms');
            $table->boolean('is_change');
            $table->string('action', 8);
            $table->string('reason', 64);
            $table->json('payload');
            $table->index(['market_id', 'period', 'recorded_at_ms'], 'signal_market_history');
            $table->index(['market_id', 'is_change', 'recorded_at_ms'], 'signal_changes');
        });
        Schema::create('market_suggestion_dismissals', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->string('exchange', 64);
            $table->string('symbol', 32);
            $table->timestamp('dismissed_at');
            $table->primary(['user_id', 'exchange', 'symbol'], 'suggestion_dismissal_identity');
        });
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('dashboard_seen_at_ms')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('dashboard_seen_at_ms'));
        Schema::dropIfExists('market_suggestion_dismissals');
        Schema::dropIfExists('market_signals');
    }
};
