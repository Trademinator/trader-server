<?php

use App\Domain\Intelligence\KnnEnsemble;
use App\Domain\Intelligence\SignalSemantics;

it('describes supported turning point actions without position semantics', function () {
    expect(SignalSemantics::actionMeaning('buy', 'supported'))
        ->toBe('supported_bottom_with_upward_future_move');
    expect(SignalSemantics::actionMeaning('sell', 'supported'))
        ->toBe('supported_top_with_downward_future_move');
    expect(SignalSemantics::actionMeaning('hodl', 'supported'))->toBeNull();
    expect(SignalSemantics::actionMeaning('sell', 'stale_signal'))->toBeNull();
});

it('describes ensemble evidence without claiming an objective future outcome for human annotations', function () {
    $scoring = ['version' => KnnEnsemble::VERSION];
    expect(SignalSemantics::actionMeaning('buy', 'supported', $scoring))->toBe('supported_buy_by_weighted_models')
        ->and(SignalSemantics::actionMeaning('sell', 'supported', $scoring))->toBe('supported_sell_by_weighted_models')
        ->and(SignalSemantics::actionMeaning('hodl', 'supported', $scoring))->toBeNull()
        ->and(SignalSemantics::actionMeaning('buy', 'weak_model_consensus', $scoring))->toBeNull();
});
