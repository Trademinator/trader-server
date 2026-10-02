<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_feeds', function (Blueprint $table) {
            $table->unsignedInteger('selection_version')->default(0)->after('selected_period');
            $table->timestamp('selection_checked_at')->nullable()->after('selection_version');
            $table->timestamp('selection_next_attempt_at')->nullable()->index()->after('selection_checked_at');
        });

        Schema::table('market_history_backfills', function (Blueprint $table) {
            $table->unsignedBigInteger('target_start_ms')->nullable()->after('period');
        });
    }

    public function down(): void
    {
        Schema::table('market_history_backfills', function (Blueprint $table) {
            $table->dropColumn('target_start_ms');
        });

        Schema::table('market_feeds', function (Blueprint $table) {
            $table->dropIndex(['selection_next_attempt_at']);
            $table->dropColumn(['selection_version', 'selection_checked_at', 'selection_next_attempt_at']);
        });
    }
};
