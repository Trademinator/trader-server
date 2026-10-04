<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Client\RiskFactorCalculator;
use App\Http\Controllers\Controller;
use App\Models\MarketPreferenceProfile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

final class ClientRiskFactorController extends Controller
{
    public function __invoke(Request $request, RiskFactorCalculator $calculator): JsonResponse
    {
        // The authenticated key determines the user. No caller-supplied user,
        // subscription, exchange, answers or R value can override this lookup.
        // Query on every request so edits/removal are visible immediately.
        $profile = MarketPreferenceProfile::query()->whereKey($request->user()->getKey())->first();

        try {
            $answers = $profile?->answers;
        } catch (DecryptException|JsonException) {
            return $this->unavailable();
        }

        // A corrupt saved profile is not an absent questionnaire. Do not turn
        // storage failures into a successful response with the 0.25 default.
        if ($profile !== null && (! is_array($answers) || ($answers !== [] && array_is_list($answers)))) {
            return $this->unavailable();
        }

        return response()->json([
            'api_version' => 1,
            ...$calculator->calculate($answers),
            'questionnaire_updated_at' => $profile?->updated_at?->toISOString(),
            'calculated_at' => now()->toISOString(),
        ]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'api_version' => 1,
            'error' => [
                'code' => 'risk_profile_unavailable',
                'message' => 'The saved risk questionnaire could not be read. No risk factor was issued.',
            ],
        ], 503);
    }
}
