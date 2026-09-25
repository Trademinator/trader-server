<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_context_snapshots', function (Blueprint $table) {
            $table->uuid('snapshot_id')->primary();
            $table->string('coin_id', 128);
            $table->string('vs_currency', 32);
            $table->unsignedBigInteger('observed_at_ms');
            $table->json('payload');
            $table->index(['coin_id', 'vs_currency', 'observed_at_ms'], 'context_lookup');
        });
        Schema::create('market_features', function (Blueprint $table) {
            $table->uuid('feature_id')->primary();
            $table->string('exchange', 64);
            $table->string('symbol', 32);
            $table->string('period', 4);
            $table->unsignedBigInteger('microtimestamp');
            $table->unsignedBigInteger('available_at_ms');
            $table->string('version', 32);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['exchange', 'symbol', 'period', 'microtimestamp', 'version'], 'market_feature_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_features');
        Schema::dropIfExists('market_context_snapshots');
    }
};
