<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_training_snapshots', function (Blueprint $table): void {
            $table->uuid('snapshot_id')->primary();
            $table->char('snapshot_key', 64)->unique();
            $table->char('market_key', 64);
            $table->uuid('dataset_id');
            $table->unsignedBigInteger('decision_at_ms');
            $table->string('version', 40);
            $table->char('sha256', 64);
            $table->json('payload');
            $table->timestamp('created_at');
            $table->index(['market_key', 'decision_at_ms'], 'human_snapshot_market_time');
        });
        Schema::create('human_training_reviews', function (Blueprint $table): void {
            $table->uuid('review_id')->primary();
            $table->uuid('snapshot_id');
            $table->foreign('snapshot_id')->references('snapshot_id')->on('human_training_snapshots')->restrictOnDelete();
            // Preserve pseudonymous provenance even if the trainer later deletes their account.
            $table->uuid('trainer_id');
            $table->string('label', 16)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('shown_at', 3);
            $table->timestamp('expires_at', 3);
            $table->timestamp('submitted_at', 3)->nullable();
            $table->unique(['snapshot_id', 'trainer_id'], 'human_review_one_per_trainer');
            $table->index(['trainer_id', 'submitted_at'], 'human_review_trainer_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('human_training_reviews');
        Schema::dropIfExists('human_training_snapshots');
    }
};
