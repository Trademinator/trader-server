<?php

namespace App\Domain\MarketSuggestions;

final class Questionnaire
{
    public const BANDS = [
        'unsure' => ['Not sure / prefer not to say', null],
        'under_100' => ['Under 100', 100],
        '100_500' => ['100–500', 500],
        '500_2000' => ['500–2,000', 2000],
        '2000_10000' => ['2,000–10,000', 10000],
        'over_10000' => ['Over 10,000', null],
    ];

    public const PROVINCES = ['AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon'];

    public static function countries(): array
    {
        $countries = [];
        foreach (\ResourceBundle::create('en', 'ICUDATA-region')->get('Countries') as $code => $name) {
            if (preg_match('/^[A-Z]{2}$/D', $code) && ! in_array($code, ['ZZ', 'EU', 'UN', 'XA', 'XB', 'QO'], true)) {
                $countries[$code] = $name;
            }
        }
        asort($countries, SORT_NATURAL | SORT_FLAG_CASE);

        return $countries;
    }

    public static function choices(): array
    {
        return [
            'goal' => ['grow' => 'Grow value in my reference currency', 'accumulate' => 'Accumulate a particular asset', 'learn' => 'Learn and follow markets'],
            'risk' => ['unsure' => 'Not sure', 'low' => 'A 10% decline would concern me', 'medium' => 'I can tolerate larger swings, but a 20% decline would concern me', 'high' => 'I accept substantial swings and the possibility of losing my allocation'],
            'loss_impact' => ['unsure' => 'Not sure / prefer not to say', 'yes' => 'Yes', 'no' => 'No'],
            'money_needed' => ['unsure' => 'Not sure', 'soon' => 'Within the next month', 'months' => 'Within the next year', 'later' => 'Not within the next year'],
            'horizon' => ['unsure' => 'Not sure', 'hours' => 'Hours', 'days' => 'Several days', 'weeks' => 'Several weeks'],
            'experience' => ['new' => 'New to spot trading', 'some' => 'Some experience', 'experienced' => 'Experienced'],
            'monitoring' => ['occasional' => 'A few times a week or less', 'daily' => 'Once a day', 'frequent' => 'Several times a day'],
            'conversions' => ['direct' => 'Only pairs funded by assets already here', 'one' => 'One conversion on this exchange is acceptable'],
        ];
    }

    public static function defaults(): array
    {
        return ['country' => '', 'region' => '', 'exchange' => '', 'access_confirmed' => false,
            'reference_currency' => 'CAD', 'allocation' => 'unsure', 'goal' => 'learn', 'target_asset' => '',
            'risk' => 'unsure', 'loss_impact' => 'unsure', 'money_needed' => 'unsure', 'horizon' => 'unsure',
            'experience' => 'new', 'monitoring' => 'daily', 'conversions' => 'direct',
            'excluded_assets' => '', 'exclude_stablecoins' => false, 'holdings' => []];
    }
}
