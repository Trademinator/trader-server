<?php

use App\Domain\Intelligence\HumanTrainingProgress;
use App\Domain\Intelligence\OptionalGuidance;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\TrainMarketIntelligence;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

function humanProgressRecords(TestHandler $handler): array
{
    return array_map(fn ($record): array => json_decode($record->message, true), $handler->getRecords());
}

it('gives human training five minutes even when the automatic allowance is nearly spent', function () {
    config(['human_training.enabled' => true, 'human_training.candle_enabled' => true]);
    $started = microtime(true);
    $deadline = OptionalGuidance::publicationDeadline($started + 1);
    $humanDeadline = null;

    $result = OptionalGuidance::compare('candle', 'test', $deadline, function (float $limit) use (&$humanDeadline): array {
        $humanDeadline = $limit;

        return ['bundle' => ['status' => 'validated', 'influence' => true]];
    });

    expect($humanDeadline)->toBeGreaterThanOrEqual($started + 300)->toBeLessThanOrEqual($deadline - 10);
    expect($result['bundle'])->toMatchArray(['status' => 'validated', 'influence' => true, 'optional' => true]);
});

it('keeps the original publication deadline when candle training is disabled', function () {
    config(['human_training.candle_enabled' => false]);
    $called = false;

    $result = OptionalGuidance::compare('candle', 'test', 1000, function () use (&$called): array {
        $called = true;

        return [];
    });

    expect(OptionalGuidance::publicationDeadline(1000))->toBe(1000.0);
    expect($called)->toBeFalse();
    expect($result['bundle'])->toMatchArray(['status' => 'disabled', 'influence' => false]);
});

it('records the last stage and counts when an optional timeout keeps the automatic fallback', function () {
    config(['human_training.enabled' => true, 'human_training.candle_enabled' => true]);
    $handler = new TestHandler;
    $log = new ActionLog(new Logger('test', [$handler]), new ActionContext);
    $progress = new HumanTrainingProgress($log, ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m']);

    $result = OptionalGuidance::compare('candle', 'test', microtime(true) + 400, function () use ($progress): array {
        $progress->stage('tuning_natural', ['processed' => 17, 'total' => 100]);
        throw new RuntimeException('Candle guidance training time budget exceeded.');
    }, $progress);

    $records = humanProgressRecords($handler);
    expect($result['bundle'])->toMatchArray(['status' => 'optional_budget_exhausted', 'influence' => false]);
    expect($records)->toHaveCount(3);
    expect($records[2])->toMatchArray(['event' => 'intelligence.human_training.timeout', 'outcome' => 'timeout',
        'stage' => 'tuning_natural', 'processed' => 17, 'total' => 100, 'budget_seconds' => 300,
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m']);
    expect($handler->hasErrorRecords())->toBeTrue();
});

it('reports an exhausted outer deadline without attempting training', function () {
    config(['human_training.enabled' => true, 'human_training.candle_enabled' => true]);
    $handler = new TestHandler;
    $progress = new HumanTrainingProgress(new ActionLog(new Logger('test', [$handler]), new ActionContext), []);

    $result = OptionalGuidance::compare('candle', 'test', microtime(true) + 1,
        fn (): array => throw new LogicException('must not execute'), $progress);

    expect($result['bundle']['status'])->toBe('optional_budget_exhausted');
    expect(humanProgressRecords($handler)[1])->toMatchArray(['event' => 'intelligence.human_training.timeout',
        'reason' => 'no_time_remaining', 'remaining_ms' => 0]);
});

it('logs unexpected failures safely and propagates them', function (Throwable $error) {
    config(['human_training.enabled' => true, 'human_training.candle_enabled' => true]);
    $handler = new TestHandler;
    $progress = new HumanTrainingProgress(new ActionLog(new Logger('test', [$handler]), new ActionContext), []);

    expect(fn () => OptionalGuidance::compare('candle', 'test', microtime(true) + 400,
        fn (): array => throw $error, $progress))->toThrow($error::class, 'PRIVATE_PAYLOAD');

    expect(humanProgressRecords($handler)[1])->toMatchArray(['event' => 'intelligence.human_training.failed',
        'reason' => 'training_error', 'error' => $error::class]);
    expect(json_encode($handler->getRecords()))->not->toContain('PRIVATE_PAYLOAD');
})->with([new LogicException('PRIVATE_PAYLOAD'), new RuntimeException('PRIVATE_PAYLOAD')]);

it('logs stage changes immediately and progress every ten seconds without flooding the log', function () {
    $handler = new TestHandler;
    $time = 1000.0;
    $clock = function () use (&$time): float {
        return $time;
    };
    $progress = new HumanTrainingProgress(new ActionLog(new Logger('test', [$handler]), new ActionContext),
        ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'payload' => 'PRIVATE_PAYLOAD'], $clock);

    $progress->start(1300, 300);
    $progress->stage('holdout', ['processed' => 0, 'total' => 100]);
    $time = 1009;
    $progress->tick(['processed' => 20]);
    $time = 1010;
    $progress->tick(['processed' => 21]);
    $progress->stage('holdout', ['processed' => 22]);
    $time = 1015;
    $progress->tick(['processed' => 100]);
    $progress->finish('completed', 'validated');

    $records = humanProgressRecords($handler);
    expect($records)->toHaveCount(4);
    expect($records[2])->toMatchArray(['stage' => 'holdout', 'processed' => 21, 'total' => 100,
        'duration_ms' => 10000, 'remaining_ms' => 290000]);
    expect($records[3])->toMatchArray(['event' => 'intelligence.human_training.completed',
        'reason' => 'validated', 'processed' => 100, 'duration_ms' => 15000]);
    expect(json_encode($handler->getRecords()))->not->toContain('PRIVATE_PAYLOAD');
});

it('keeps training job timeouts above the combined budget and queue reservations above the jobs', function () {
    config(['human_training.enabled' => true, 'human_training.candle_enabled' => true]);
    $jobs = [new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', 'test'),
        new RebuildBackfilledIntelligence('history', 'lease')];
    $buildSeconds = OptionalGuidance::publicationDeadline((float) config('intelligence.max_seconds'));

    foreach ($jobs as $job) {
        expect($job->timeout)->toBeGreaterThan($buildSeconds);
        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            expect(config('queue.connections.'.$connection.'.retry_after'))->toBeGreaterThan($job->timeout);
        }
    }
});
