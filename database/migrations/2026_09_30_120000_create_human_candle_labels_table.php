<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_candle_labels', function (Blueprint $table): void {
            $table->uuid('candle_label_id')->primary();
            $table->uuid('snapshot_id');
            $table->foreign('snapshot_id')->references('snapshot_id')->on('human_training_snapshots')->restrictOnDelete();
            $table->uuid('trainer_id');
            $table->string('action', 8);
            $table->timestamp('created_at', 3);
            $table->timestamp('updated_at', 3);
            $table->unique(['snapshot_id', 'trainer_id'], 'human_candle_one_per_trainer');
            $table->index(['trainer_id', 'updated_at'], 'human_candle_trainer_updated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('human_candle_labels');
    }
};
