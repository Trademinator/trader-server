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
        Schema::table('exchanges', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC');
            $table->string('timezone_source', 32)->default('unknown');
            $table->string('region_prior', 128)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchanges', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'timezone_source', 'region_prior']);
        });
    }
};
