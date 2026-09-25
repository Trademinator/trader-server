<?php

use function Trademinator\Time\periods_to_seconds;

it('uses lowercase 1y as the canonical yearly timeframe', function () {
    expect(periods_to_seconds('1y'))->toBe(31_536_000)
        ->and(periods_to_seconds('1Y'))->toBe(31_536_000);
});
