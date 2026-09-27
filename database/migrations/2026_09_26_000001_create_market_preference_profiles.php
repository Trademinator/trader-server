<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_preference_profiles', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->text('answers');
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_preference_profiles');
    }
};
