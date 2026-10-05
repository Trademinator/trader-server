<?php

/** Standalone domain regression checks: php tests/Support/intelligence-recovery-checks.php */
use App\Domain\Intelligence\CandleGuidance;
use App\Domain\Intelligence\ClassPriorWeights;
use App\Domain\Intelligence\OptionalGuidance;
use App\Domain\Intelligence\SnapshotInput;
use App\Domain\Intelligence\TrainingRowAudit;
use App\Domain\MarketData\HistoryReplayWindow;

$root = dirname(__DIR__, 2);
foreach (['Intelligence/ClassPriorWeights', 'Intelligence/TrainingRowAudit', 'Intelligence/SnapshotInput',
    'MarketData/HistoryReplayWindow', 'Intelligence/OptionalGuidance', 'Intelligence/CandleGuidance'] as $file) {
    require_once $root.'/app/Domain/'.$file.'.php';
}
$settings = ['human_training.enabled' => true, 'human_training.trend_enabled' => true,
    'human_training.candle_enabled' => true, 'human_training.publication_reserve_seconds' => 10,
    'human_training.auxiliary_max_seconds' => 90];
if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        global $settings;
        return $settings[$key] ?? $default;
    }
}
function recoveryAssert(bool $condition, string $message = 'Assertion failed'): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function recoveryThrows(Closure $callback, string $class = InvalidArgumentException::class): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        recoveryAssert($error instanceof $class, 'Wrong exception: '.get_class($error));
        return;
    }
    throw new RuntimeException('Expected exception '.$class);
}
function recoveryNear(float $actual, float $expected): void
{
    recoveryAssert(abs($actual - $expected) < 1e-12, "$actual != $expected");
}
$cases = [];
$cases['natural weights preserve frequencies'] = function (): void {
    $weights = ClassPriorWeights::fit(['buy' => 100, 'hold' => 800, 'sell' => 100]);
    recoveryAssert($weights === ['buy' => 1.0, 'hold' => 1.0, 'sell' => 1.0]);
    recoveryAssert(ClassPriorWeights::apply(['buy' => .1, 'hold' => .8, 'sell' => .1], $weights) === ['buy' => .1, 'hold' => .8, 'sell' => .1]);
};
$cases['target weighting retains all counts and yields aggregate 25/50/25'] = function (): void {
    $counts = ['buy' => 100, 'hold' => 800, 'sell' => 100];
    $weights = ClassPriorWeights::fit($counts, ClassPriorWeights::TARGET);
    recoveryAssert($counts === ['buy' => 100, 'hold' => 800, 'sell' => 100]);
    recoveryNear($weights['buy'], 2.5); recoveryNear($weights['hold'], .625); recoveryNear($weights['sell'], 2.5);
    $shares = ClassPriorWeights::apply(['buy' => .1, 'hold' => .8, 'sell' => .1], $weights);
    recoveryNear($shares['buy'], .25); recoveryNear($shares['hold'], .50); recoveryNear($shares['sell'], .25);
};
$cases['a HOLD-only neighbourhood remains HOLD-only'] = function (): void {
    $weights = ClassPriorWeights::fit(['buy' => 1, 'hold' => 900, 'sell' => 1], ClassPriorWeights::TARGET);
    recoveryAssert(ClassPriorWeights::apply(['hold' => 1.0], $weights) === ['buy' => 0.0, 'hold' => 1.0, 'sell' => 0.0]);
};
$cases['missing classes do not acquire synthetic votes'] = function (): void {
    $weights = ClassPriorWeights::fit(['buy' => 0, 'hold' => 90, 'sell' => 10], ClassPriorWeights::TARGET);
    recoveryAssert($weights['buy'] === 0.0);
    recoveryAssert(ClassPriorWeights::apply(['hold' => .9, 'sell' => .1], $weights)['buy'] === 0.0);
};
$cases['empty neighbourhood stays empty'] = fn () => recoveryAssert(ClassPriorWeights::apply([], ['buy' => 1.0, 'hold' => 1.0, 'sell' => 1.0]) === ['buy' => 0.0, 'hold' => 0.0, 'sell' => 0.0]);
foreach ([['buy'=>-1,'hold'=>2,'sell'=>1], ['buy'=>0,'hold'=>0,'sell'=>0], ['buy'=>1.5,'hold'=>2,'sell'=>1], ['buy'=>1,'hold'=>2]] as $i => $bad) {
    $cases['reject invalid counts '.$i] = fn () => recoveryThrows(fn () => ClassPriorWeights::fit($bad));
}
foreach ([['buy'=>.3,'hold'=>.5,'sell'=>.3], ['buy'=>NAN,'hold'=>.5,'sell'=>.25], ['buy'=>.25,'hold'=>0,'sell'=>.75], ['buy'=>.25,'hold'=>.75]] as $i => $bad) {
    $cases['reject invalid target '.$i] = fn () => recoveryThrows(fn () => ClassPriorWeights::fit(['buy'=>1,'hold'=>8,'sell'=>1], $bad));
}
$cases['reject nonfinite shares'] = fn () => recoveryThrows(fn () => ClassPriorWeights::apply(['buy'=>INF], ['buy'=>1,'hold'=>1,'sell'=>1]));
$cases['reject overflowing vote total'] = fn () => recoveryThrows(fn () => ClassPriorWeights::apply(['buy'=>PHP_FLOAT_MAX], ['buy'=>PHP_FLOAT_MAX,'hold'=>1,'sell'=>1]));
$cases['deduplicate identical candle identity once'] = function (): void {
    $row = ['decision_at_ms'=>1000,'action'=>'hold','vector'=>[.5,.2]];
    $audit = TrainingRowAudit::inspect([$row, $row]);
    recoveryAssert($audit['duplicates'] === 1 && $audit['unique_rows'] === 1 && $audit['input_rows'] === 2);
};
$cases['distinct repeated HOLD states are retained chronologically'] = function (): void {
    $rows = [['decision_at_ms'=>2000,'action'=>'hold','vector'=>[.5]], ['decision_at_ms'=>1000,'action'=>'hold','vector'=>[.5]]];
    $audit = TrainingRowAudit::inspect($rows);
    recoveryAssert($audit['unique_rows'] === 2 && $audit['duplicates'] === 0);
    recoveryAssert(array_column($audit['rows'], 'decision_at_ms') === [1000,2000]);
};
$cases['key order is not a duplicate conflict'] = function (): void {
    $a = ['decision_at_ms'=>1,'source'=>['b'=>2,'a'=>1]];
    $b = ['source'=>['a'=>1,'b'=>2],'decision_at_ms'=>1];
    recoveryAssert(TrainingRowAudit::inspect([$a,$b])['duplicates'] === 1);
};
$cases['conflicting labels on same identity fail closed'] = fn () => recoveryThrows(fn () => TrainingRowAudit::inspect([['decision_at_ms'=>1,'action'=>'buy'], ['decision_at_ms'=>1,'action'=>'sell']]));
$cases['conflicting vectors on same identity fail closed'] = fn () => recoveryThrows(fn () => TrainingRowAudit::inspect([['decision_at_ms'=>1,'vector'=>[.1]], ['decision_at_ms'=>1,'vector'=>[.2]]]));
$cases['missing identity rejected'] = fn () => recoveryThrows(fn () => TrainingRowAudit::inspect([['action'=>'hold']]));
$cases['empty audit is valid'] = fn () => recoveryAssert(TrainingRowAudit::inspect([])['unique_rows'] === 0);
$payload = ['version'=>'v1','keys'=>['body'],'vector'=>[.5], 'series'=>[['time'=>1000,'close'=>'2']], 'model_observation'=>null];
$cases['model observations and revision metadata do not change review input identity'] = function () use ($payload): void {
    $updated = [...$payload,'model_observation'=>['action'=>'buy'],'revision'=>['previous_snapshot_id'=>'old']];
    recoveryAssert(SnapshotInput::digest($payload) === SnapshotInput::digest($updated));
};
$cases['chart correction changes snapshot identity'] = function () use ($payload): void {
    $changed = $payload; $changed['series'][0]['close'] = '3';
    recoveryAssert(SnapshotInput::digest($changed) !== SnapshotInput::digest($payload));
};
$cases['feature correction changes snapshot identity'] = function () use ($payload): void {
    recoveryAssert(SnapshotInput::digest([...$payload,'vector'=>[.6]]) !== SnapshotInput::digest($payload));
};
$cases['different schema changes profile'] = fn () => recoveryAssert(SnapshotInput::profile([...$payload,'keys'=>['rsi']]) !== SnapshotInput::profile($payload));
$cases['market and candle identities remain distinct'] = function () use ($payload): void {
    recoveryAssert(SnapshotInput::key('market-a', 1, $payload) !== SnapshotInput::key('market-b', 1, $payload));
    recoveryAssert(SnapshotInput::key('market-a', 1, $payload) !== SnapshotInput::key('market-a', 2, $payload));
};
$ledger = [['revision'=>3,'from_ms'=>300,'to_ms'=>400],['revision'=>4,'from_ms'=>100,'to_ms'=>200]];
$cases['replay starts at earliest change in complete revision ledger'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(2,4,$ledger) === 100);
$cases['newer changes outside target do not get marked processed'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(2,3,[$ledger[0]]) === 300);
$cases['missing legacy revision falls back to full replay'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(1,4,$ledger) === null);
$cases['unknown explicit rebuild range uses full replay'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(0,1,[['revision'=>1,'from_ms'=>null,'to_ms'=>null]]) === null);
$cases['partial ledger falls back to full replay'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(2,4,[$ledger[0]]) === null);
$cases['reordered ledger is rejected conservatively'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(2,4,array_reverse($ledger)) === null);
$cases['invalid source interval uses full replay'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(0,1,[['revision'=>1,'from_ms'=>20,'to_ms'=>10]]) === null);
$cases['backward revision target rejected'] = fn () => recoveryThrows(fn () => HistoryReplayWindow::earliest(4,3,[]));
$cases['object ledger records are supported'] = fn () => recoveryAssert(HistoryReplayWindow::earliest(2,4,array_map(fn ($row)=>(object)$row,$ledger)) === 100);
$cases['trend can be disabled without disabling candle guidance'] = function () use (&$settings): void {
    $settings['human_training.trend_enabled'] = false;
    recoveryAssert(! OptionalGuidance::enabled('trend') && OptionalGuidance::enabled('candle'));
    $result = OptionalGuidance::compare('trend','v',microtime(true)+120,fn () => throw new LogicException('must not execute'));
    recoveryAssert($result['bundle']['status'] === 'disabled' && $result['bundle']['influence'] === false);
    $settings['human_training.trend_enabled'] = true;
};
$cases['candle can be disabled without disabling trend guidance'] = function () use (&$settings): void {
    $settings['human_training.candle_enabled'] = false;
    recoveryAssert(! OptionalGuidance::enabled('candle') && OptionalGuidance::enabled('trend'));
    $settings['human_training.candle_enabled'] = true;
};
$cases['master switch disables both enhancements'] = function () use (&$settings): void {
    $settings['human_training.enabled'] = false;
    recoveryAssert(! OptionalGuidance::enabled('candle') && ! OptionalGuidance::enabled('trend'));
    $settings['human_training.enabled'] = true;
};
$cases['publication reserve skips expensive optional work'] = function (): void {
    $result = OptionalGuidance::compare('trend','v',microtime(true)+1,fn () => throw new LogicException('must not execute'));
    recoveryAssert($result['bundle']['status'] === 'optional_budget_exhausted');
};
$cases['known optional timeout retains the baseline path'] = function (): void {
    $result = OptionalGuidance::compare('candle','v',microtime(true)+120,fn () => throw new RuntimeException('Candle guidance training time budget exceeded.'));
    recoveryAssert(! $result['bundle']['influence'] && $result['bundle']['keys'] === []);
};
$cases['checksum failures are never swallowed'] = fn () => recoveryThrows(fn () => OptionalGuidance::compare('trend','v',microtime(true)+120,fn () => throw new LogicException('Human training snapshot checksum mismatch.')), LogicException::class);
$cases['unknown runtime failures are never swallowed'] = fn () => recoveryThrows(fn () => OptionalGuidance::compare('trend','v',microtime(true)+120,fn () => throw new RuntimeException('Source history corrupt')), RuntimeException::class);
$cases['successful optional comparison receives bounded deadline'] = function (): void {
    $now = microtime(true);
    $result = OptionalGuidance::compare('trend','v',$now+120,function ($deadline) use ($now) {
        recoveryAssert($deadline <= $now+91 && $deadline > $now);
        return ['bundle'=>['influence'=>false,'keys'=>[]]];
    });
    recoveryAssert($result['bundle']['optional']);
};
$cases['old candle artifacts remain explicitly readable'] = fn () => recoveryAssert(in_array('m4.4-candle-guidance-v2', CandleGuidance::READABLE_VERSIONS, true));
$cases['holdout metrics cannot break a tuning tie'] = function (): void {
    $score = ['semantic_precision'=>.6,'coverage'=>.1,'contradiction_rate'=>.01,'mean_confidence'=>.8];
    $method = new ReflectionMethod(CandleGuidance::class, 'betterTuning');
    recoveryAssert($method->invoke(new CandleGuidance, [...$score,'holdout'=>['semantic_precision'=>1]], [...$score,'holdout'=>['semantic_precision'=>0]]) === false);
};
$failed = 0;
foreach ($cases as $name => $case) {
    try {
        $case(); echo "PASS $name\n";
    } catch (Throwable $error) {
        $failed++; fwrite(STDERR, "FAIL $name: ".$error->getMessage()."\n");
    }
}
echo (count($cases)-$failed).'/'.count($cases)." domain cases passed\n";
exit($failed ? 1 : 0);
