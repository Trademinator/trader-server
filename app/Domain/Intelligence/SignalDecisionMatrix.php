<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

final class SignalDecisionMatrix
{
    public static function decide(string $action, string $outcome): string
    {
        if (! in_array($action, ['buy', 'hodl', 'sell'], true)
            || ! in_array($outcome, ['super_bear', 'bear', 'neutral', 'bull', 'super_bull'], true)) {
            throw new InvalidArgumentException('Unknown Action or Outcome KNN class.');
        }

        return match ($action) {
            'sell' => in_array($outcome, ['super_bear', 'bear', 'neutral'], true) ? 'sell' : 'hodl',
            'buy' => in_array($outcome, ['neutral', 'bull', 'super_bull'], true) ? 'buy' : 'hodl',
            default => 'hodl',
        };
    }
    public static function resolve(array $action, array $outcome): array
    {
        $actionSupported = ($action['reason'] ?? null) === 'supported';
        $outcomeSupported = ($outcome['reason'] ?? null) === 'supported';

        if ($actionSupported && $outcomeSupported) {
            return [
                'action' => self::decide($action['action'], $outcome['outcome']),
                'reason' => 'supported',
                'mode' => 'full',
                'confidence' => min((float) $action['confidence'], (float) $outcome['confidence']),
                'neighbors' => min((int) $action['neighbors'], (int) $outcome['neighbors']),
                'effective_neighbors' => min((float) $action['effective_neighbors'], (float) $outcome['effective_neighbors']),
                'similarity' => min((float) $action['similarity'], (float) $outcome['similarity']),
            ];
        }

        if ($actionSupported) {
            $rawAction = (string) $action['action'];
            // An unavailable Outcome cannot veto supported Action KNN evidence.
            // Unknown actions still fail closed to HOLD.
            $resolved = in_array($rawAction, ['buy', 'hodl', 'sell'], true) ? $rawAction : 'hodl';
            $preserved = $resolved === $rawAction;

            return [
                'action' => $resolved,
                'reason' => 'degraded_action_only',
                'mode' => 'degraded_action_only',
                'confidence' => $preserved ? (float) $action['confidence'] : 0.0,
                'neighbors' => $preserved ? (int) $action['neighbors'] : 0,
                'effective_neighbors' => $preserved ? (float) $action['effective_neighbors'] : 0.0,
                'similarity' => $preserved ? (float) $action['similarity'] : 0.0,
            ];
        }

        if ($outcomeSupported) {
            return ['action' => 'hodl', 'reason' => 'degraded_outcome_only', 'mode' => 'degraded_outcome_only',
                'confidence' => 0.0, 'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0];
        }

        return ['action' => 'hodl', 'reason' => 'knn_abstention', 'mode' => 'abstaining',
            'confidence' => 0.0, 'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0];
    }

}
