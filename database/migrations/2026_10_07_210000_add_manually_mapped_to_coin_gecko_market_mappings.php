<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_gecko_market_mappings', function (Blueprint $table): void {
            $table->boolean('manually_mapped')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('coin_gecko_market_mappings', function (Blueprint $table): void {
            $table->dropColumn('manually_mapped');
        });
    }
};
