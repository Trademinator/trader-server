<?php

namespace App\Domain\Intelligence;

use App\Models\Exchange;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ExchangeTimezone
{
    public static function valid(string $timezone): bool
    {
        return $timezone === 'UTC' || in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /** A country is a weak metadata prior, never evidence of customer geography. */
    public function seed(Exchange $exchange, array $metadata): void
    {
        if (($exchange->timezone_source ?? 'unknown') !== 'unknown') {
            return;
        }
        $countries = array_values(array_filter((array) ($metadata['countries'] ?? []),
            fn ($country): bool => is_string($country) && preg_match('/^[A-Z]{2}$/D', $country)));
        $zones = count($countries) === 1 ? DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countries[0]) : [];
        // Multi-zone and multi-country metadata cannot identify a unique local session.
        $exchange->forceFill(['region_prior' => $countries === [] ? null : implode(',', $countries),
            'timezone' => count($zones) === 1 ? $zones[0] : 'UTC',
            'timezone_source' => count($zones) === 1 ? 'country_prior' : 'unknown']);
        if ($exchange->isDirty()) {
            $exchange->save();
        }
    }

    public function context(Exchange $exchange): array
    {
        $zone = $exchange->timezone ?? 'UTC';
        if (! self::valid($zone)) {
            throw new InvalidArgumentException('Invalid stored exchange IANA timezone.');
        }

        return ['timezone' => $zone, 'timezone_source' => $exchange->timezone_source ?? 'unknown',
            'region_prior' => $exchange->region_prior];
    }

    public static function session(int $timestampMs, string $timezone): string
    {
        $date = (new DateTimeImmutable('@'.intdiv($timestampMs, 1000)))->setTimezone(new DateTimeZone($timezone));

        return sprintf('%02d-%02d', intdiv((int) $date->format('G'), 6) * 6, (intdiv((int) $date->format('G'), 6) + 1) * 6);
    }
}
