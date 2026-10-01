<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_credentials', function (Blueprint $table): void {
            $table->uuid('exchange_credential_id')->primary();
            $table->uuid('user_id');
            $table->uuid('exchange_id');
            $table->text('credentials');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'exchange_id']);
            $table->index(['exchange_id', 'is_shared']);
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('exchange_id')->references('exchange_id')->on('exchanges')->cascadeOnDelete();
        });

        Schema::create('exchange_credential_rotations', function (Blueprint $table): void {
            $table->string('scope', 160)->primary();
            $table->uuid('last_credential_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_credential_rotations');
        Schema::dropIfExists('exchange_credentials');
    }
};
