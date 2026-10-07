<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_features', function (Blueprint $table) {
            $table->index(['version', 'created_at'], 'market_feature_status_version');
        });
    }

    public function down(): void
    {
        Schema::table('market_features', function (Blueprint $table) {
            $table->dropIndex('market_feature_status_version');
        });
    }
};
