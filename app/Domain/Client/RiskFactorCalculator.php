<?php

declare(strict_types=1);

namespace App\Domain\Client;

use LogicException;
use RoundingMode;

/**
 * Versioned allocation policy, not a loss probability or trading permission.
 *
 * S = sum(weight * restraint), R = 100^(-S).
 * Scores and weights bound S to [0, 1], so R is naturally in [0.01, 1].
 * Changes to weights, mappings or the unknown-answer baseline require a new
 * algorithm version. These are product policy weights, not fitted estimates.
 */
final class RiskFactorCalculator
{
    public const VERSION = 'questionnaire-risk-v1';

    public const OUTPUT_SCALE = 16;

    private const WORK_SCALE = 32;

    // ln(4) / ln(100) = log10(2). Mathematical constants, not fitted scores.
    private const UNKNOWN_SCORE = '0.3010299956639811952137388947244930267682';

    private const LN_100 = '4.6051701859880913680359829093687284152022';

    private const POLICY = [
        'loss_impact' => ['weight' => '0.40', 'scores' => ['no' => '0', 'yes' => '1']],
        'risk' => ['weight' => '0.30', 'scores' => ['high' => '0', 'medium' => '0.5', 'low' => '1']],
        'money_needed' => ['weight' => '0.20', 'scores' => ['later' => '0', 'months' => '0.5', 'soon' => '1']],
        'experience' => ['weight' => '0.10', 'scores' => ['experienced' => '0', 'some' => '0.5', 'new' => '1']],
    ];

    /**
     * Null means no saved questionnaire; an empty array means saved, unknown
     * answers. Never pass null here as a fallback for a failed database read
     * or failed decryption. Unrelated questionnaire fields are not returned.
     *
     * @param array<string, mixed>|null $answers
     * @return array<string, mixed>
     */
    public function calculate(?array $answers): array
    {
        if ($answers === null) {
            return [
                'risk_factor' => '0.2500000000000000',
                'algorithm_version' => self::VERSION,
                'source' => 'default',
                'restraint_score' => null,
                'components' => [],
                'unknown_fields' => [],
            ];
        }

        $score = '0';
        $components = [];
        $unknownFields = [];

        foreach (self::POLICY as $field => $policy) {
            $answer = $answers[$field] ?? null;
            $known = is_string($answer) && array_key_exists($answer, $policy['scores']);
            $restraint = $known ? $policy['scores'][$answer] : self::UNKNOWN_SCORE;
            $weightedScore = bcmul($policy['weight'], $restraint, self::WORK_SCALE);
            $score = bcadd($score, $weightedScore, self::WORK_SCALE);
            $status = match (true) {
                $known => 'answered',
                $answer === 'unsure' => 'unsure',
                $answer === null => 'missing',
                default => 'unrecognized',
            };

            if (! $known) {
                $unknownFields[] = $field;
            }

            $components[$field] = [
                // Do not echo arbitrary values from legacy/malformed profiles.
                'answer' => $known || $answer === 'unsure' ? $answer : null,
                'status' => $status,
                'weight' => $policy['weight'],
                'score' => $this->decimal($restraint),
                'weighted_score' => $this->decimal($weightedScore),
            ];
        }

        return [
            'risk_factor' => $this->decimal($this->allocationFactor($score)),
            'algorithm_version' => self::VERSION,
            'source' => 'questionnaire',
            'restraint_score' => $this->decimal($score),
            'components' => $components,
            'unknown_fields' => $unknownFields,
        ];
    }

    /**
     * Evaluate 100^(-S) with decimal arithmetic, without a float pow/log or
     * fractional bcpow exponent (native bcpow only accepts integer exponents).
     *
     * Write y = S * ln(100) / 16, then R = 1 / exp(y)^16. Since 0 <= y < 0.288,
     * the positive Taylor series converges quickly without cancellation.
     * Four squarings undo the range reduction. The iteration limit is a
     * convergence safeguard, not a cap on R; failure is an error, not a default.
     */
    private function allocationFactor(string $score): string
    {
        if (bccomp($score, '0', self::WORK_SCALE) < 0 || bccomp($score, '1', self::WORK_SCALE) > 0) {
            throw new LogicException('Risk restraint score is outside the versioned policy domain.');
        }

        $argument = bcdiv(bcmul($score, self::LN_100, self::WORK_SCALE), '16', self::WORK_SCALE);
        $term = '1';
        $sum = '1';
        $converged = false;

        for ($n = 1; $n <= 64; $n++) {
            $term = bcdiv(bcmul($term, $argument, self::WORK_SCALE), (string) $n, self::WORK_SCALE);
            if (bccomp($term, '0', self::WORK_SCALE) === 0) {
                $converged = true;
                break;
            }
            $sum = bcadd($sum, $term, self::WORK_SCALE);
        }

        if (! $converged) {
            throw new LogicException('Risk factor exponential did not converge.');
        }

        for ($n = 0; $n < 4; $n++) {
            $sum = bcmul($sum, $sum, self::WORK_SCALE);
        }

        return bcdiv('1', $sum, self::WORK_SCALE);
    }

    private function decimal(string $value): string
    {
        return bcround($value, self::OUTPUT_SCALE, RoundingMode::HalfEven);
    }
}
