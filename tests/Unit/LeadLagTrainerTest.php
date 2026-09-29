<?php

use App\Domain\Intelligence\ExchangeTimezone;
use App\Domain\Intelligence\LeadLagTrainer;
use Tests\Support\LeadLagFixtures;

function leadLagSettings(): array
{
    return require __DIR__.'/../../config/lead_lag.php';
}

function leadLagContext(): array
{
    return ['timezone' => 'UTC', 'timezone_source' => 'unknown', 'region_prior' => null];
}

it('recovers the directed delay and sign with purged selection and persistent later evidence', function (float $sign) {
    [$leader, $follower] = LeadLagFixtures::series(direction: $sign);

    $result = (new LeadLagTrainer)->train($leader, $follower, 60000, leadLagContext(), leadLagSettings(), microtime(true) + 10);

    expect($result['report']['status'])->toBe('validated');
    expect($result['model']['lag'])->toBe(3);
    expect($result['report']['direction'])->toBe($sign > 0 ? 'same' : 'opposite');
    expect($result['report']['evaluation']['skill'])->toBeGreaterThan(0.95);
    expect($result['report']['train_labels_available_by_ms'])->toBeLessThan($result['report']['selection_from_ms']);
    expect($result['report']['selection_labels_available_by_ms'])->toBeLessThan($result['report']['test_from_ms']);
})->with([1.0, -1.0]);

it('keeps the later holdout out of lag selection and removes influence after a regime reversal', function () {
    [$leader, $follower] = LeadLagFixtures::series();
    $trainer = new LeadLagTrainer;
    $first = $trainer->train($leader, $follower, 60000, leadLagContext(), leadLagSettings(), microtime(true) + 10);
    foreach ($follower as $at => &$bar) {
        if ($at >= $first['report']['test_from_ms']) {
            $bar['return'] *= -1;
        }
    }
    unset($bar);

    $second = $trainer->train($leader, $follower, 60000, leadLagContext(), leadLagSettings(), microtime(true) + 10);

    expect($second['report']['candidates'])->toBe($first['report']['candidates']);
    expect($second['model'])->toBeNull();
    expect($second['report']['strength'])->toBe(0.0);
});

it('does not promote contemporaneous prices or independent movement to a leader', function (string $mode) {
    [$leader, $follower] = LeadLagFixtures::series();
    foreach ($follower as $at => &$bar) {
        $bar['return'] = $mode === 'same_time' ? $leader[$at]['return'] : LeadLagFixtures::noise($at, 'independent');
    }
    unset($bar);

    $result = (new LeadLagTrainer)->train($leader, $follower, 60000, leadLagContext(), leadLagSettings(), microtime(true) + 10);

    expect($result['model'])->toBeNull();
})->with(['same_time', 'independent']);

it('aligns by timestamps and requires a complete future interval instead of matching row positions', function () {
    [$leader, $follower] = LeadLagFixtures::series(150);
    $follower = array_combine(array_map(fn ($at) => $at + 30000, array_keys($follower)), array_values($follower));

    $result = (new LeadLagTrainer)->train($leader, $follower, 60000, leadLagContext(), leadLagSettings(), microtime(true) + 10);

    expect($result['report']['samples'])->toBe(0);
    expect($result['model'])->toBeNull();
});

it('uses IANA daylight saving rules for local session buckets', function () {
    $winter = (new DateTimeImmutable('2024-01-15 10:30:00 UTC'))->getTimestamp() * 1000;
    $summer = (new DateTimeImmutable('2024-07-15 10:30:00 UTC'))->getTimestamp() * 1000;

    expect(ExchangeTimezone::session($winter, 'America/Toronto'))->toBe('00-06');
    expect(ExchangeTimezone::session($summer, 'America/Toronto'))->toBe('06-12');
    expect(ExchangeTimezone::valid('EST+5'))->toBeFalse();
});

it('learns a local session effect instead of assuming an exchange leads throughout the day', function () {
    [$leader, $follower] = LeadLagFixtures::series(8000);
    foreach ($follower as $at => &$bar) {
        if (ExchangeTimezone::session($at - 3 * 60000, 'UTC') !== '00-06') {
            $bar['return'] = LeadLagFixtures::noise($at, 'session-noise');
        }
    }
    unset($bar);
    $context = ['timezone' => 'UTC', 'timezone_source' => 'operator', 'region_prior' => null];

    $result = (new LeadLagTrainer)->train($leader, $follower, 60000, $context, leadLagSettings(), microtime(true) + 20);

    expect($result['report']['status'])->toBe('validated');
    expect($result['model']['session'])->toBe('00-06');
    expect($result['model']['lag'])->toBe(3);
});
