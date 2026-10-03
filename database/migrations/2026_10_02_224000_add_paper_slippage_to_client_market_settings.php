<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_market_settings', function (Blueprint $table): void {
            $table->decimal('paper_slippage_bps', 12, 4)->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('client_market_settings', function (Blueprint $table): void {
            $table->dropColumn('paper_slippage_bps');
        });
    }
};
