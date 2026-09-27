<?php

namespace App\Domain\MarketSuggestions;

use Carbon\CarbonImmutable;
use Throwable;

final class RegionalAccess
{
    public function check(array $answers): array
    {
        $reviews = config('market_suggestions.regional_reviews.'.$answers['exchange'], []);
        $country = $answers['country'];
        $key = $country.(! empty($answers['region']) ? '-'.$answers['region'] : '');
        $review = $reviews[$key] ?? $reviews[$country] ?? null;
        $base = ['blocked' => false, 'verified' => false, 'allowed_symbols' => null, 'excluded_symbols' => [], 'source' => null];
        if (($reviews[$country]['allowed'] ?? null) === false || ($reviews[$key]['allowed'] ?? null) === false) {
            return array_replace($base, ['blocked' => true, 'message' => 'The site’s regional review excludes this exchange for your residence.']);
        }
        if (is_array($review)) {
            // Denials remain protective even when a review needs updating.
            if (($review['allowed'] ?? null) === false) {
                return array_replace($base, ['blocked' => true, 'message' => 'The site’s regional review excludes this exchange for your residence.']);
            }
            $base['allowed_symbols'] = $review['allowed_symbols'] ?? null;
            $base['excluded_symbols'] = $review['excluded_symbols'] ?? [];
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $review['reviewed_at'] ?? '');
                $validSource = filter_var($review['source'] ?? '', FILTER_VALIDATE_URL)
                    && parse_url($review['source'], PHP_URL_SCHEME) === 'https';
                if (($review['allowed'] ?? null) === true && $date && $validSource
                    && $date->toDateString() === ($review['reviewed_at'] ?? null)
                    && $date->lessThanOrEqualTo(now())
                    && $date->greaterThanOrEqualTo(now()->startOfDay()->subDays((int) config('market_suggestions.regional_review_max_days', 90)))) {
                    return array_replace($base, ['verified' => true, 'source' => $review['source'],
                        'message' => 'Regional access reviewed by the site on '.$date->toDateString().'. Confirm this pair is available in your own account.']);
                }
            } catch (Throwable) {
                // An invalid/stale review is unknown, never an approval.
            }
        }

        return array_replace($base, ['message' => ! empty($answers['access_confirmed'])
            ? 'You confirmed account access. The site has no current independent regional review; these are pairs to explore only.'
            : 'Regional access is unconfirmed. Confirm availability with the exchange before considering these pairs.']);
    }
}
