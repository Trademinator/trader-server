<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_signals', function (Blueprint $table): void {
            $table->index(['market_id', 'period', 'decision_at_ms', 'recorded_at_ms'],
                'signal_market_candle_decisions');
        });
    }

    public function down(): void
    {
        Schema::table('market_signals', function (Blueprint $table): void {
            $table->dropIndex('signal_market_candle_decisions');
        });
    }
};
