<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_action_label_analyses', function (Blueprint $table): void {
            $table->collation = 'utf8mb4_bin';
            $table->string('exchange', 64);
            $table->string('symbol', 128);
            $table->string('period', 16);
            $table->unsignedBigInteger('as_of_ms');
            $table->json('analysis');
            $table->uuid('dataset_id')->nullable();
            $table->string('source', 16);
            $table->timestamps();
            $table->primary(['exchange', 'symbol', 'period'], 'market_action_label_market');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_action_label_analyses');
    }
};
