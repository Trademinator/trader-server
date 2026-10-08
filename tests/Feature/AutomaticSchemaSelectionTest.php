<?php

use App\Domain\Research\FeatureSchema;

it('keeps CoinGecko optional for Enhanced and Full', function (): void {
    expect(FeatureSchema::keys('enhanced'))->toBe(FeatureSchema::keys('core'))
        ->and(FeatureSchema::keys('full'))->toBe(FeatureSchema::keys('technical'))
        ->and(FeatureSchema::keys('core'))->toHaveCount(15)
        ->and(FeatureSchema::keys('full'))->toHaveCount(18)
        ->and(FeatureSchema::modelCompatible(['outcome' => ['schema' => 'full'],
            'keys' => FeatureSchema::keys('full')]))->toBeTrue()
        ->and(FeatureSchema::modelCompatible(['outcome' => ['schema' => 'full'],
            'keys' => array_merge(FeatureSchema::keys('full'), ['context.global_regime'])]))->toBeFalse();
});
