<?php

namespace App\Domain\Archive;

final class PortableJson
{
    public const FORMAT = 'trademinator-portable-jsonl';

    public static function encode(array $value): string
    {
        self::assertPortable($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function decode(string $json): array
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new ArchiveIntegrityException('Portable JSON record must be an object.');
        }
        self::assertPortable($value);

        return $value;
    }

    private static function assertPortable(mixed $value): void
    {
        if (is_float($value)) {
            throw new ArchiveIntegrityException('Portable archive values must not use binary floating point. Store financial decimals as strings.');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::assertPortable($child);
            }
        }
    }
}
