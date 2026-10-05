<?php

namespace App\Domain\Research;

use InvalidArgumentException;

final class WalkForward
{
    /** Strictly prior, fully observed labels only. Test blocks never overlap. */
    public function folds(array $rows, int $trainSize, int $testSize, int $gap = 0, bool $expanding = true, ?int $maxFolds = 1000): \Generator
    {
        if ($trainSize < 1 || $testSize < 1 || $gap < 0) {
            throw new InvalidArgumentException('Train/test sizes must be positive; gap must be nonnegative.');
        }
        $previous = null;
        foreach ($rows as $row) {
            if (($previous !== null && $row['decision_at_ms'] <= $previous)
                || $row['label_available_at_ms'] <= $row['decision_at_ms']) {
                throw new InvalidArgumentException('Walk-forward rows must be chronological with future label endpoints.');
            }
            $previous = $row['decision_at_ms'];
        }
        $fold = 0;
        for ($start = $trainSize + $gap; $start < count($rows);) {
            $cutoff = $rows[$start]['decision_at_ms'];
            $train = [];
            for ($i = 0; $i < $start - $gap; $i++) {
                if ($rows[$i]['label_available_at_ms'] < $cutoff) {
                    $train[] = $i;
                }
            }
            if (count($train) < $trainSize) {
                $start++;

                continue;
            }
            $excluded = $start - count($train);
            if (! $expanding) {
                $train = array_slice($train, -$trainSize);
            }
            $test = range($start, min($start + $testSize, count($rows)) - 1);
            if ($maxFolds !== null && $fold >= $maxFolds) {
                throw new InvalidArgumentException('Backtests are limited to 1000 folds; increase --test or reduce the dataset range.');
            }
            yield ['fold' => ++$fold, 'train' => $train, 'test' => $test,
                'purged_or_gap_rows' => $excluded,
            ];
            $start += $testSize;
        }
    }
}
