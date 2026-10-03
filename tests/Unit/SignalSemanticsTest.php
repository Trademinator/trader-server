<?php

use App\Domain\Intelligence\SignalSemantics;

it('describes supported turning point actions without position semantics', function () {
    expect(SignalSemantics::actionMeaning('buy', 'supported'))
        ->toBe('supported_bottom_with_upward_future_move');
    expect(SignalSemantics::actionMeaning('sell', 'supported'))
        ->toBe('supported_top_with_downward_future_move');
    expect(SignalSemantics::actionMeaning('hodl', 'supported'))->toBeNull();
    expect(SignalSemantics::actionMeaning('sell', 'stale_signal'))->toBeNull();
});
