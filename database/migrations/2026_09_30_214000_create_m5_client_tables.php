<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_api_keys', function (Blueprint $table): void {
            $table->uuid('client_api_key_id')->primary();
            $table->uuid('user_id');
            $table->string('label', 80);
            $table->string('prefix', 12)->unique();
            $table->char('secret_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });

        Schema::create('client_market_settings', function (Blueprint $table): void {
            $table->uuid('client_market_setting_id')->primary();
            $table->uuid('user_id');
            $table->uuid('market_subscription_id')->unique();
            $table->boolean('trading_enabled')->default(false);
            $table->boolean('paper_enabled')->default(false);
            $table->decimal('max_order_quote', 30, 12)->nullable();
            $table->decimal('max_position_quote', 30, 12)->nullable();
            $table->decimal('reserve_quote', 30, 12)->default(0);
            $table->decimal('max_spread_bps', 12, 4)->default(100);
            $table->decimal('max_taker_fee_bps', 12, 4)->default(100);
            $table->decimal('min_signal_confidence', 8, 7)->default(0.6);
            $table->boolean('block_conflicting_exposure')->default(true);
            $table->decimal('paper_initial_quote', 30, 12)->default(10000);
            $table->boolean('alert_on_signal_change')->default(false);
            $table->boolean('alert_on_execution_failure')->default(false);
            $table->string('digest_frequency', 8)->default('off');
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('market_subscription_id')->references('market_subscription_id')->on('market_subscriptions')->cascadeOnDelete();
        });

        Schema::create('client_execution_reports', function (Blueprint $table): void {
            $table->uuid('client_execution_report_id')->primary();
            $table->uuid('user_id');
            $table->uuid('market_subscription_id');
            $table->uuid('market_signal_id');
            $table->string('idempotency_key', 96);
            $table->char('request_hash', 64);
            $table->string('event', 16);
            $table->string('side', 4)->nullable();
            $table->string('reason', 64)->nullable();
            $table->boolean('protective')->default(false);
            $table->decimal('quantity', 30, 18)->nullable();
            $table->decimal('price', 30, 18)->nullable();
            $table->decimal('fee', 30, 18)->nullable();
            $table->string('fee_currency', 16)->nullable();
            $table->string('exchange_order_id', 128)->nullable();
            $table->string('exchange_trade_id', 128)->nullable();
            $table->unsignedBigInteger('occurred_at_ms');
            $table->unsignedBigInteger('recorded_at_ms');
            $table->unique(['user_id', 'idempotency_key'], 'client_report_idempotency');
            $table->index(['market_subscription_id', 'occurred_at_ms'], 'client_report_market_time');
            $table->index(['market_signal_id', 'occurred_at_ms'], 'client_report_signal_time');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('market_subscription_id')->references('market_subscription_id')->on('market_subscriptions')->cascadeOnDelete();
            $table->foreign('market_signal_id')->references('market_signal_id')->on('market_signals')->cascadeOnDelete();
        });

        Schema::create('client_paper_accounts', function (Blueprint $table): void {
            $table->uuid('client_paper_account_id')->primary();
            $table->uuid('user_id');
            $table->uuid('market_subscription_id');
            $table->decimal('quote_balance', 30, 18);
            $table->decimal('base_balance', 30, 18);
            $table->decimal('initial_quote_balance', 30, 18);
            $table->decimal('benchmark_base_quantity', 30, 18);
            $table->decimal('benchmark_start_price', 30, 18);
            $table->decimal('peak_equity', 30, 18);
            $table->decimal('realized_fees_quote', 30, 18)->default(0);
            $table->unsignedBigInteger('started_at_ms');
            $table->timestamps();
            $table->unique(['user_id', 'market_subscription_id'], 'client_paper_account_market');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('market_subscription_id')->references('market_subscription_id')->on('market_subscriptions')->cascadeOnDelete();
        });

        Schema::create('client_paper_events', function (Blueprint $table): void {
            $table->uuid('client_paper_event_id')->primary();
            $table->uuid('client_paper_account_id');
            $table->uuid('market_signal_id')->nullable();
            $table->string('idempotency_key', 96);
            $table->char('request_hash', 64);
            $table->string('event', 16);
            $table->string('reason', 64);
            $table->string('side', 4)->nullable();
            $table->decimal('quantity', 30, 18)->nullable();
            $table->decimal('price', 30, 18)->nullable();
            $table->decimal('fee_quote', 30, 18)->nullable();
            $table->unsignedBigInteger('occurred_at_ms');
            $table->unsignedBigInteger('recorded_at_ms');
            $table->json('result');
            $table->unique(['client_paper_account_id', 'idempotency_key'], 'client_paper_idempotency');
            $table->index(['client_paper_account_id', 'occurred_at_ms'], 'client_paper_time');
            $table->foreign('client_paper_account_id')->references('client_paper_account_id')->on('client_paper_accounts')->cascadeOnDelete();
            $table->foreign('market_signal_id')->references('market_signal_id')->on('market_signals')->nullOnDelete();
        });

        if (Schema::hasColumn('users', 'api_key')) {
            foreach (DB::table('users')->whereNotNull('api_key')->get(['user_id', 'api_key']) as $user) {
                $secret = (string) $user->api_key;
                if ($secret === '') {
                    continue;
                }
                $prefix = substr($secret, 0, 12);
                while (DB::table('client_api_keys')->where('prefix', $prefix)->exists()) {
                    $prefix = substr(hash('sha256', $user->user_id.$prefix), 0, 12);
                }
                DB::table('client_api_keys')->insert([
                    'client_api_key_id' => (string) Str::uuid7(), 'user_id' => $user->user_id,
                    'label' => 'Migrated legacy key', 'prefix' => $prefix, 'secret_hash' => hash('sha256', $secret),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('users')->whereNotNull('api_key')->update(['api_key' => null]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_paper_events');
        Schema::dropIfExists('client_paper_accounts');
        Schema::dropIfExists('client_execution_reports');
        Schema::dropIfExists('client_market_settings');
        Schema::dropIfExists('client_api_keys');
    }
};
