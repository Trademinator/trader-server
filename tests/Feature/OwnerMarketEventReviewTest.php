<?php

use App\Models\MarketEventCandidate;
use App\Models\User;

function gdeltReviewCandidate(): MarketEventCandidate
{
    return MarketEventCandidate::query()->create([
        'source_hash' => hash('sha256', 'https://example.test/fork'),
        'provider' => 'gdelt',
        'source_url' => 'https://example.test/fork',
        'source_domain' => 'example.test',
        'source_title' => 'XYZ hard fork announced',
        'source_seen_at' => now(),
        'event_type' => 'protocol_hard_fork',
        'matched_symbols' => ['XYZ'],
        'machine_confidence' => 0.71,
        'machine_evidence' => ['families' => ['fork']],
    ]);
}

it('restricts the GDELT review queue and decisions to server owners', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $candidate = gdeltReviewCandidate();
    $ordinary = User::factory()->create();

    $this->actingAs($ordinary)->get('/owner/events')->assertForbidden();
    $this->actingAs($ordinary)->put('/owner/events/'.$candidate->market_event_candidate_id, ['decision' => 'yes'])->assertForbidden();
});

it('lets an owner confirm or reject a GDELT candidate without changing machine evidence', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $candidate = gdeltReviewCandidate();

    $this->actingAs($owner)->get('/owner/events')->assertOk()->assertSee('XYZ hard fork announced')->assertSee('Confirm YES')->assertSee('Confirm NO');
    $this->put('/owner/events/'.$candidate->market_event_candidate_id, ['decision' => 'yes'])->assertRedirect();

    $candidate->refresh();
    expect($candidate->owner_decision)->toBe('yes')
        ->and($candidate->reviewed_by)->toBe($owner->user_id)
        ->and($candidate->reviewed_at)->not->toBeNull()
        ->and($candidate->machine_evidence['families'])->toBe(['fork']);

    $this->put('/owner/events/'.$candidate->market_event_candidate_id, ['decision' => 'no'])->assertRedirect();
    expect($candidate->fresh()->owner_decision)->toBe('no');
});
