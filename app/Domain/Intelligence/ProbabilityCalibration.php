<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

/** Isotonic calibration (pool-adjacent-violators), fitted only on a prior calibration block. */
final class ProbabilityCalibration
{
    public function fit(array $probabilities, array $labels): array
    {
        if ($probabilities === [] || count($probabilities) !== count($labels)) {
            throw new InvalidArgumentException('Calibration requires matching nonempty probabilities and labels.');
        }
        $pairs = [];
        foreach ($probabilities as $i => $probability) {
            $pairs[] = [$probability, $labels[$i] === 'completed' ? 1 : 0];
        }
        usort($pairs, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $blocks = [];
        foreach ($pairs as [$probability, $label]) {
            $blocks[] = ['upper' => $probability, 'sum' => $label, 'count' => 1];
            while (count($blocks) > 1) {
                $last = count($blocks) - 1;
                $a = $blocks[$last - 1];
                $b = $blocks[$last];
                if ($a['upper'] < $b['upper'] && $a['sum'] / $a['count'] <= $b['sum'] / $b['count']) {
                    break;
                }
                array_pop($blocks);
                $blocks[$last - 1] = ['upper' => $b['upper'], 'sum' => $a['sum'] + $b['sum'], 'count' => $a['count'] + $b['count']];
            }
        }

        return array_map(fn (array $block): array => ['upper' => $block['upper'],
            'probability' => $block['sum'] / $block['count']], $blocks);
    }

    public function apply(float $probability, array $blocks): float
    {
        foreach ($blocks as $block) {
            if ($probability <= $block['upper']) {
                return $block['probability'];
            }
        }

        return $blocks[array_key_last($blocks)]['probability'];
    }

    public function metrics(array $probabilities, array $labels): array
    {
        $brier = $logLoss = 0.0;
        $bins = array_fill(0, 10, ['count' => 0, 'probability_sum' => 0.0, 'completed' => 0]);
        foreach ($probabilities as $i => $p) {
            $y = $labels[$i] === 'completed' ? 1 : 0;
            $brier += ($p - $y) ** 2;
            $clipped = max(1e-9, min(1 - 1e-9, $p));
            $logLoss -= $y * log($clipped) + (1 - $y) * log(1 - $clipped);
            $bin = min(9, (int) floor($p * 10));
            $bins[$bin]['count']++;
            $bins[$bin]['probability_sum'] += $p;
            $bins[$bin]['completed'] += $y;
        }
        $count = count($labels);
        $ece = 0.0;
        foreach ($bins as &$bin) {
            $bin['mean_probability'] = $bin['count'] ? $bin['probability_sum'] / $bin['count'] : null;
            $bin['completion_rate'] = $bin['count'] ? $bin['completed'] / $bin['count'] : null;
            $ece += $bin['count'] * abs(($bin['mean_probability'] ?? 0) - ($bin['completion_rate'] ?? 0));
            unset($bin['probability_sum']);
        }
        unset($bin);

        return ['rows' => $count, 'brier' => $count ? $brier / $count : null,
            'log_loss' => $count ? $logLoss / $count : null, 'calibration_error' => $count ? $ece / $count : null,
            'reliability_bins' => $bins];
    }
}
