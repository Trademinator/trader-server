<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_models', function (Blueprint $table) {
            $table->uuid('model_id')->primary();
            $table->uuid('dataset_id');
            $table->string('market_key', 64)->index();
            $table->string('status', 32);
            $table->char('generation_key', 64)->nullable()->unique();
            $table->char('sha256', 64);
            $table->json('report');
            $table->timestamp('created_at');
            $table->foreign('dataset_id')->references('dataset_id')->on('research_datasets')->restrictOnDelete();
        });
        Schema::create('intelligence_heads', function (Blueprint $table) {
            $table->char('market_key', 64)->primary();
            $table->uuid('model_id');
            $table->foreign('model_id')->references('model_id')->on('intelligence_models')->restrictOnDelete();
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_heads');
        Schema::dropIfExists('intelligence_models');
    }
};
