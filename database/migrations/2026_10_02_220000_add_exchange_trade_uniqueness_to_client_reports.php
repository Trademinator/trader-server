<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('client_execution_reports')
            ->select('user_id', 'market_subscription_id', 'exchange_trade_id', DB::raw('COUNT(*) AS duplicate_count'))
            ->whereNotNull('exchange_trade_id')
            ->groupBy('user_id', 'market_subscription_id', 'exchange_trade_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Cannot add exchange-trade uniqueness: duplicate non-null exchange_trade_id values already exist. '
                .'Resolve the duplicate client execution reports before rerunning this migration.'
            );
        }

        Schema::table('client_execution_reports', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'market_subscription_id', 'exchange_trade_id'],
                'client_report_exchange_trade_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('client_execution_reports', function (Blueprint $table): void {
            $table->dropUnique('client_report_exchange_trade_unique');
        });
    }
};
