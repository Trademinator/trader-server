<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candle_period_selections', function (Blueprint $table) {
            $table->uuid('selection_id')->primary();
            $table->string('exchange', 32);
            $table->string('symbol', 32);
            $table->string('period', 4);
            $table->unsignedBigInteger('sample_from_ms');
            $table->unsignedBigInteger('sample_to_ms');
            $table->decimal('threshold', 17, 15);
            $table->decimal('minimum_coverage', 17, 15);
            $table->json('quality');
            $table->timestamp('created_at');
            $table->index(['exchange', 'symbol', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candle_period_selections');
    }
};
