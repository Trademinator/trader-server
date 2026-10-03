<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_history_backfills', function (Blueprint $table) {
            $table->text('build_performance')->nullable()->after('build_error');
        });
    }

    public function down(): void
    {
        Schema::table('market_history_backfills', function (Blueprint $table) {
            $table->dropColumn('build_performance');
        });
    }
};
