<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickers', function (Blueprint $table) {
            $table->collation = 'utf8mb4_bin';
            $table->uuid('ticker_id')->primary();
            $table->unsignedBigInteger('microtimestamp');
            $table->string('exchange', 32);
            $table->string('symbol', 32);
            $table->enum('period', ['1m', '3m', '5m', '15m', '30m', '45m', '1h', '2h', '3h', '4h', '6h', '8h', '12h', '1d', '3d', '7d', '1w', '2w', '1M', '3M', '4M', '1y']);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['exchange', 'symbol', 'period', 'microtimestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickers');
    }
};
