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
     * @param  list<array>  $rows
     * @param  array<int, true>  $recordedDecisions
     * @param  callable(array): ?object  $resolve
     * @param  callable(object): bool  $hasOpinion
     * @return array{row: array, snapshot: object}|null
     */
    public function select(array $rows, array $recordedDecisions, callable $resolve, callable $hasOpinion,
        int $attempts = self::MAX_CHECKS, bool $allowReviewed = false): ?array
    {
        $unrecorded = $recorded = [];
        foreach ($rows as $row) {
            if (isset($recordedDecisions[(int) $row['decision_at_ms']])) {
                $recorded[] = $row;
            } else {
                $unrecorded[] = $row;
            }
        }
        shuffle($unrecorded);
        shuffle($recorded);
        $limit = min(self::MAX_CHECKS, max(1, $attempts));
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
}
