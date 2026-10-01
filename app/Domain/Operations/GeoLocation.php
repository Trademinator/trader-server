<?php

namespace App\Domain\Operations;

use MaxMind\Db\Reader;
use Throwable;

class GeoLocation
{
    public const UNKNOWN = ['country' => 'ZZ', 'region' => '', 'city' => 'Unknown'];

    /** @return array{country: string, region: string, city: string} */
    public function locate(?string $ip): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return self::UNKNOWN;
        }
        $reader = null;
        try {
            $path = (string) config('operations.geoip_database');
            if (! is_readable($path)) {
                return self::UNKNOWN;
            }
            $reader = new Reader($path);
            if (! str_contains($reader->metadata()->databaseType, 'City')) {
                return self::UNKNOWN;
            }
            $record = $reader->get($ip);
            $country = $record['country']['iso_code'] ?? 'ZZ';

            return ['country' => preg_match('/^[A-Z]{2}$/D', $country) ? $country : 'ZZ',
                'region' => $this->label($record['subdivisions'][0]['names']['en'] ?? ''),
                'city' => $this->label($record['city']['names']['en'] ?? 'Unknown')];
        } catch (Throwable) {
            return self::UNKNOWN;
        } finally {
            $reader?->close();
        }
    }

    public function status(): array
    {
        $reader = null;
        try {
            $path = (string) config('operations.geoip_database');
            if (! is_readable($path)) {
                return ['status' => 'missing', 'built_at' => null];
            }
            $reader = new Reader($path);
            $metadata = $reader->metadata();

            return ['status' => str_contains($metadata->databaseType, 'City') ? 'ready' : 'wrong_database',
                'built_at' => gmdate('Y-m-d\TH:i:s\Z', $metadata->buildEpoch)];
        } catch (Throwable) {
            return ['status' => 'unreadable', 'built_at' => null];
        } finally {
            $reader?->close();
        }
    }

    private function label(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $value), 0, 120);
    }
}
