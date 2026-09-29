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
        Schema::create('access_daily_stats', function (Blueprint $table) {
            $table->char('bucket_id', 64)->primary();
            $table->date('day')->index();
            $table->string('route', 160);
            $table->string('method', 8);
            $table->unsignedSmallInteger('status_code');
            $table->boolean('authenticated');
            $table->char('country', 2);
            $table->string('region', 120);
            $table->string('city', 120);
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);
        });
        Schema::create('access_daily_visitors', function (Blueprint $table) {
            $table->date('day');
            $table->char('visitor_hash', 64);
            $table->char('country', 2);
            $table->string('region', 120);
            $table->string('city', 120);
            $table->primary(['day', 'visitor_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_daily_visitors');
        Schema::dropIfExists('access_daily_stats');
    }
};
