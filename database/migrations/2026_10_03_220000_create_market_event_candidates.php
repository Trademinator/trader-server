<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_event_candidates', function (Blueprint $table): void {
            $table->uuid('market_event_candidate_id')->primary();
            $table->char('source_hash', 64)->unique();
            $table->string('provider', 16)->default('gdelt');
            $table->text('source_url');
            $table->string('source_domain')->nullable();
            $table->text('source_title');
            $table->timestamp('source_seen_at')->nullable();
            $table->string('source_language', 64)->nullable();
            $table->string('source_country', 128)->nullable();
            $table->text('context_snippet')->nullable();
            $table->string('event_type', 32)->default('unknown');
            $table->json('matched_symbols')->nullable();
            $table->decimal('machine_confidence', 5, 4)->default(0);
            $table->json('machine_evidence');
            $table->string('owner_decision', 3)->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('user_id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['owner_decision', 'source_seen_at'], 'market_event_review_queue');
            $table->index(['event_type', 'source_seen_at'], 'market_event_type_seen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_event_candidates');
    }
};
