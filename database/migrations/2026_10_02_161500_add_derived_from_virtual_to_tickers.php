<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('tickers', function (Blueprint $table) use ($driver): void {
            $expression = $driver === 'sqlite'
                ? "json_extract(payload, '$.derived_from')"
                : "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.derived_from'))";

            $table->string('derived_from', 8)->virtualAs($expression);
            $table->index(
                ['exchange', 'symbol', 'period', 'derived_from'],
                'tickers_market_derived_from_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tickers', function (Blueprint $table): void {
            $table->dropIndex('tickers_market_derived_from_index');
            $table->dropColumn('derived_from');
        });
    }
};
