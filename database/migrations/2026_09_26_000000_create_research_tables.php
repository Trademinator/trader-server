<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_datasets', function (Blueprint $table) {
            $table->uuid('dataset_id')->primary();
            $table->json('manifest');
            $table->timestamp('created_at');
        });
        Schema::create('research_backtests', function (Blueprint $table) {
            $table->uuid('backtest_id')->primary();
            $table->uuid('dataset_id');
            $table->json('report');
            $table->timestamp('created_at');
            $table->foreign('dataset_id')->references('dataset_id')->on('research_datasets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_backtests');
        Schema::dropIfExists('research_datasets');
    }
};
