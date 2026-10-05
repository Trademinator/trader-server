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
        Schema::table('client_paper_accounts', function (Blueprint $table): void {
            $table->decimal('valuation_price', 30, 18)->nullable();
            $table->string('valuation_source', 32)->nullable();
            $table->unsignedBigInteger('valuation_at_ms')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_paper_accounts', function (Blueprint $table): void {
            $table->dropColumn(['valuation_price', 'valuation_source', 'valuation_at_ms']);
        });
    }
};
