<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_gecko_market_mappings', function (Blueprint $table) {
            $table->uuid('coin_gecko_market_mapping_id')->primary();
            $table->uuid('market_id')->unique();
            $table->string('base_symbol', 32)->nullable()->index();
            $table->string('vs_currency', 32)->nullable();
            $table->string('coin_id', 128)->nullable()->index();
            $table->string('coin_name', 128)->nullable();
            $table->string('category', 128)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('market_id')->references('market_id')->on('markets')->cascadeOnDelete();
            $table->index(['coin_id', 'vs_currency', 'status'], 'coingecko_mapping_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_gecko_market_mappings');
    }
};
