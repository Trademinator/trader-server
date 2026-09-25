<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->collation = 'utf8mb4_bin';
            $table->uuid('market_id')->primary();
            $table->uuid('exchange_id');
            $table->string('symbol', 32);
            $table->decimal('tick_size', 30, 18);
            $table->timestamps();
            $table->unique(['exchange_id', 'symbol']);
            $table->foreign('exchange_id')->references('exchange_id')->on('exchanges')->restrictOnDelete();
        });

        Schema::create('market_subscriptions', function (Blueprint $table) {
            $table->uuid('market_subscription_id')->primary();
            $table->uuid('user_id');
            $table->uuid('market_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'market_id']);
            $table->index(['market_id', 'active']);
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('market_id')->references('market_id')->on('markets')->cascadeOnDelete();
        });

        Schema::create('market_feeds', function (Blueprint $table) {
            $table->uuid('market_id')->primary();
            $table->string('selected_period', 4)->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('next_pull_at')->nullable()->index();
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->foreign('market_id')->references('market_id')->on('markets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_feeds');
        Schema::dropIfExists('market_subscriptions');
        Schema::dropIfExists('markets');
    }
};
