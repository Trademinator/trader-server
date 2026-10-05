<?php

/** No framework required: exercises the production selector, not a duplicate algorithm. */
use App\Domain\Intelligence\TrainingCandidateSelector;

require_once dirname(__DIR__, 2).'/app/Domain/Intelligence/TrainingCandidateSelector.php';

function selectionCheck(bool $passed, string $message): void
{
    if (! $passed) {
        throw new RuntimeException($message);
    }
}

function selectionRows(int $count): array
{
    return array_map(fn (int $i): array => ['decision_at_ms' => $i + 1, 'vector' => [0.5], 'label' => 'hodl'], range(0, $count - 1));
}

$cases = [];
$cases['empty input performs no source work'] = function (): void {
    $never = fn () => throw new RuntimeException('Unexpected callback');
    selectionCheck((new TrainingCandidateSelector)->select([], [], $never, $never) === null, 'Expected no candidate');
};
$cases['one unlabelled candle is prioritized among 3000 recorded candles'] = function (): void {
    $rows = selectionRows(3000);
    $seen = array_fill_keys(range(1, 2999), true);
    $calls = 0;
    $result = (new TrainingCandidateSelector)->select($rows, $seen, function (array $row) use (&$calls): object {
        $calls++;
        selectionCheck($row['decision_at_ms'] === 3000, 'Hydrated an unrelated recorded candle');
        return (object) ['id' => 3000];
    }, fn () => false);
    selectionCheck($calls === 1 && $result['row']['decision_at_ms'] === 3000, 'Did not select in one source check');
};
$cases['all reviewed candles use at most 24 checks and retain a valid fallback'] = function (): void {
    $rows = selectionRows(3000);
    $ids = [];
    $result = (new TrainingCandidateSelector)->select($rows, array_fill_keys(range(1, 3000), true),
        function (array $row) use (&$ids): object {
            $ids[] = $row['decision_at_ms'];
            return (object) ['id' => $row['decision_at_ms']];
        }, fn () => true, allowReviewed: true);
    selectionCheck(count($ids) === 24 && count(array_unique($ids)) === 24, 'Unbounded or repeated checks');
    selectionCheck($result['snapshot']->id === $ids[0], 'Did not reuse verified fallback');
};
$cases['Trend Training never reassigns a reviewed fallback'] = function (): void {
    $rows = selectionRows(100);
    $calls = 0;
    $result = (new TrainingCandidateSelector)->select($rows, [], function () use (&$calls): object {
        $calls++;
        return new stdClass;
    }, fn () => true);
    selectionCheck($calls === 24 && $result === null, 'Trend fallback must be disabled');
};
$cases['unavailable source rows never become fallback snapshots'] = function (): void {
    $calls = 0;
    $result = (new TrainingCandidateSelector)->select(selectionRows(100), [], function () use (&$calls) {
        $calls++;
        return null;
    }, fn () => throw new RuntimeException('Checked an invalid snapshot'), allowReviewed: true);
    selectionCheck($calls === 24 && $result === null, 'Invalid source escaped validation');
};
$cases['small configured candidate budget is respected'] = function (): void {
    $calls = 0;
    (new TrainingCandidateSelector)->select(selectionRows(100), [], function () use (&$calls) {
        $calls++;
        return null;
    }, fn () => false, attempts: 3);
    selectionCheck($calls === 3, 'Ignored configured budget');
};
$cases['an oversized candidate setting cannot restore a whole-history scan'] = function (): void {
    $calls = 0;
    (new TrainingCandidateSelector)->select(selectionRows(3000), [], function () use (&$calls) {
        $calls++;
        return null;
    }, fn () => false, attempts: 1000000);
    selectionCheck($calls === 24, 'Missing hard cap');
};
$cases['a zero setting still allows one verified candidate attempt'] = function (): void {
    $calls = 0;
    (new TrainingCandidateSelector)->select(selectionRows(3), [], function () use (&$calls) {
        $calls++;
        return null;
    }, fn () => false, attempts: 0);
    selectionCheck($calls === 1, 'Invalid setting not bounded');
};
$cases['a previous-revision label is not treated as a current opinion'] = function (): void {
    $result = (new TrainingCandidateSelector)->select(selectionRows(1), [1 => true],
        fn () => (object) ['id' => 'corrected'], fn ($snapshot) => $snapshot->id === 'old');
    selectionCheck($result['snapshot']->id === 'corrected', 'Old label inherited by corrected chart');
};
$cases['a label added after metadata was read is checked again'] = function (): void {
    $calls = 0;
    $result = (new TrainingCandidateSelector)->select(selectionRows(2), [], function () use (&$calls): object {
        return (object) ['id' => ++$calls];
    }, fn ($snapshot) => $snapshot->id === 1);
    selectionCheck($calls === 2 && $result['snapshot']->id === 2, 'Metadata incorrectly overrode current opinion');
};
$cases['corrupted selected snapshots are not silently skipped'] = function (): void {
    try {
        (new TrainingCandidateSelector)->select(selectionRows(3), [], fn () => throw new LogicException('checksum'), fn () => false);
    } catch (LogicException $exception) {
        selectionCheck($exception->getMessage() === 'checksum', 'Wrong failure');
        return;
    }
    throw new RuntimeException('Checksum failure was swallowed');
};
$cases['selection preserves input rows and explicit repeated HOLDs'] = function (): void {
    $rows = selectionRows(100);
    $before = $rows;
    (new TrainingCandidateSelector)->select($rows, [], fn (array $row) => (object) $row, fn () => false);
    selectionCheck($rows === $before && count($rows) === 100, 'Changed training observations');
};
$cases['exhausting a small dataset does not repeat candidates'] = function (): void {
    $ids = [];
    $result = (new TrainingCandidateSelector)->select(selectionRows(3), [], function (array $row) use (&$ids): object {
        $ids[] = $row['decision_at_ms'];
        return (object) $row;
    }, fn () => true, allowReviewed: true);
    selectionCheck(count($ids) === 3 && count(array_unique($ids)) === 3 && $result !== null, 'Repeated source verification');
};
$cases['invalid unrecorded rows fall through to a valid corrected recorded candle'] = function (): void {
    $result = (new TrainingCandidateSelector)->select(selectionRows(2), [2 => true],
        fn (array $row) => $row['decision_at_ms'] === 1 ? null : (object) ['id' => 'revised'], fn () => false);
    selectionCheck($result['row']['decision_at_ms'] === 2, 'Did not check revised candidate');
};
foreach ($cases as $name => $case) {
    $case();
    echo 'PASS '.$name.PHP_EOL;
}
echo count($cases).'/'.count($cases).' selector checks passed'.PHP_EOL;
