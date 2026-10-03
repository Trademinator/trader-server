<?php

use App\Domain\Client\PaperExecutionModel;

it('applies fixed slippage to a paper buy without overspending the quote budget', function () {
    $fill = (new PaperExecutionModel)->fill(
        'buy', '1', '100', '100', '100', '0', '100', 'quote'
    );

    expect($fill['eligible'])->toBeTrue();
    expect((float) $fill['price'])->toBe(101.0);
    expect((float) $fill['quantity'])->toBeLessThan(1.0);
    expect((float) $fill['quote_debit'])->toBeLessThanOrEqual(100.0);
});

it('deducts a buy fee from received base when the fee asset is base', function () {
    $fill = (new PaperExecutionModel)->fill(
        'buy', '1', '100', '100', '100', '100', '0', 'base'
    );

    expect($fill['eligible'])->toBeTrue();
    expect((float) $fill['quantity'])->toBe(1.0);
    expect((float) $fill['fee_base'])->toBe(0.01);
    expect((float) $fill['fee_quote'])->toBe(0.0);
    expect((float) $fill['base_credit'])->toBe(0.99);
    expect((float) $fill['fee_quote_equivalent'])->toBe(1.0);
});

it('reserves base for a sell fee when the fee asset is base', function () {
    $fill = (new PaperExecutionModel)->fill(
        'sell', '1', '100', '100', '100', '100', '0', 'base'
    );

    expect($fill['eligible'])->toBeTrue();
    expect((float) $fill['base_debit'])->toBeLessThanOrEqual(1.0);
    expect((float) $fill['fee_base'])->toBeGreaterThan(0.0);
    expect((float) $fill['fee_quote'])->toBe(0.0);
});
