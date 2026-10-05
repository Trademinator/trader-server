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

it('keeps every affordable amount step when the exchange supplies a float', function () {
    $fill = (new PaperExecutionModel)->fill(
        'buy', '1', '1', '1', '1', '0', '0', 'quote', 0.1
    );

    expect($fill['quantity'])->toBe('1.000000000000000000');
    expect($fill['quote_debit'])->toBe('1.000000000000000000');
});

it('accepts a fill exactly at an exchange minimum supplied as a float', function (?float $minimumAmount, ?float $minimumCost) {
    $fill = (new PaperExecutionModel)->fill(
        'buy', '0.1', '0.1', '1', '1', '0', '0', 'quote', null, $minimumAmount, $minimumCost
    );

    expect($fill['eligible'])->toBeTrue();
    expect($fill['quantity'])->toBe('0.100000000000000000');
})->with([
    'minimum amount' => [0.1, null],
    'minimum cost' => [null, 0.1],
]);
