<?php

namespace App\Domain\Intelligence;

/** Keep source verification bounded independently of the number of saved opinions. */
final class TrainingCandidateSelector
{
    public const MAX_CHECKS = 24;

    /**
     * Metadata only prioritizes candidates. The current, verified snapshot and its
     * trainer-specific opinion remain authoritative, including after a repair.
     *
     * @param  iterable<array>  $rows
     * @param  array<int, true>|callable(array): bool  $recordedDecisions
     * @param  callable(array): ?object  $resolve
     * @param  callable(object): bool  $hasOpinion
     * @return array{row: array, snapshot: object}|null
     */
    public function select(iterable $rows, array|callable $recordedDecisions, callable $resolve, callable $hasOpinion,
        int $attempts = self::MAX_CHECKS, bool $allowReviewed = false): ?array
    {
        $unrecorded = $recorded = [];
        $unrecordedCount = $recordedCount = 0;
        $limit = min(self::MAX_CHECKS, max(1, $attempts));
        foreach ($rows as $row) {
            $hasRecord = is_callable($recordedDecisions)
                ? $recordedDecisions($row) : isset($recordedDecisions[(int) $row['decision_at_ms']]);
            if ($hasRecord) {
                $this->sample($recorded, $row, ++$recordedCount, $limit);
            } else {
                $this->sample($unrecorded, $row, ++$unrecordedCount, $limit);
            }
        }
        shuffle($unrecorded);
        shuffle($recorded);
        $checked = 0;
        $fallback = null;
        foreach ([$unrecorded, $recorded] as $pool) {
            foreach ($pool as $row) {
                if ($checked >= $limit) {
                    return $allowReviewed ? $fallback : null;
                }
                $checked++;
                $snapshot = $resolve($row);
                if ($snapshot === null) {
                    continue;
                }
                $candidate = ['row' => $row, 'snapshot' => $snapshot];
                if (! $hasOpinion($snapshot)) {
                    return $candidate;
                }
                $fallback ??= $candidate;
            }
        }

        return $allowReviewed ? $fallback : null;
    }

    /** Reservoir sampling keeps equal chances without retaining the whole dataset. */
    private function sample(array &$pool, array $row, int $seen, int $limit): void
    {
        if ($seen <= $limit) {
            $pool[] = $row;
        } else {
            $position = random_int(0, $seen - 1);
            if ($position < $limit) {
                $pool[$position] = $row;
            }
        }
    }
}
