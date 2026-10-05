<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('human_training_snapshots', function (Blueprint $table): void {
            // Metadata-only candidate lookup, including all snapshot revisions of a candle.
            $table->index(['market_key', 'version', 'decision_at_ms', 'snapshot_id'], 'human_snapshot_candidate_lookup');
        });
        Schema::table('market_signals', function (Blueprint $table): void {
            $table->index(['market_id', 'period', 'recorded_at_ms', 'market_signal_id'], 'signal_replay_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('market_signals', fn (Blueprint $table) => $table->dropIndex('signal_replay_lookup'));
        Schema::table('human_training_snapshots', fn (Blueprint $table) => $table->dropIndex('human_snapshot_candidate_lookup'));
    }
};
