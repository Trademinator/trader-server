<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_market_favorites', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('market_subscription_id');
            $table->timestamps();

            $table->primary(['user_id', 'market_subscription_id']);
            $table->index('market_subscription_id');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('market_subscription_id')->references('market_subscription_id')
                ->on('market_subscriptions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_market_favorites');
    }
};
