<?php

namespace App\Domain\MarketData;

use InvalidArgumentException;

/** Resolve a complete revision ledger; unknown legacy changes require a full replay. */
final class HistoryReplayWindow
{
    public static function earliest(int $trainedRevision, int $targetRevision, iterable $changes): ?int
    {
        if ($trainedRevision < 0 || $targetRevision < $trainedRevision) {
            throw new InvalidArgumentException('Invalid history revision interval.');
        }
        $expected = $trainedRevision + 1;
        $earliest = null;
        foreach ($changes as $change) {
            $change = (array) $change;
            $revision = (int) ($change['revision'] ?? -1);
            if ($revision !== $expected || $revision > $targetRevision
                || ! isset($change['from_ms'], $change['to_ms'])
                || (int) $change['from_ms'] < 0 || (int) $change['to_ms'] < (int) $change['from_ms']) {
                return null;
            }
            $earliest = $earliest === null ? (int) $change['from_ms'] : min($earliest, (int) $change['from_ms']);
            $expected++;
        }

        return $expected === $targetRevision + 1 ? $earliest : null;
    }
}
