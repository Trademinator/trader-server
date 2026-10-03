<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_market_settings', function (Blueprint $table): void {
            $table->decimal('max_signal_drift_bps', 12, 4)->default(100);
        });
    }

    public function down(): void
    {
        Schema::table('client_market_settings', function (Blueprint $table): void {
            $table->dropColumn('max_signal_drift_bps');
        });
    }
};
