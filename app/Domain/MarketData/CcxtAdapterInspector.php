<?php

namespace App\Domain\MarketData;

use ccxt\Exchange;
use Composer\InstalledVersions;
use ReflectionClass;
use RuntimeException;

/** Offline inspection only. Call in a separate process for each adapter. */
final class CcxtAdapterInspector
{
    public static function inspect(string $id): array
    {
        if (! in_array($id, Exchange::$exchanges, true)) {
            throw new RuntimeException('Unknown CCXT adapter.');
        }
        $class = '\\ccxt\\'.$id;
        $client = new $class;
        $description = $client->describe();
        $root = realpath(InstalledVersions::getInstallPath('ccxt/ccxt'));
        $files = [];
        $reflection = new ReflectionClass($client);
        do {
            $file = $reflection->getFileName();
            if (! $file || ! str_starts_with($file, $root.'/')) {
                throw new RuntimeException('Adapter source is outside the CCXT package.');
            }
            $files[substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
        } while ($reflection = $reflection->getParentClass());
        ksort($files);

        return [
            'name' => $description['name'] ?? $id,
            'spot' => ($description['has']['spot'] ?? false) === true,
            'fetchOHLCV' => $description['has']['fetchOHLCV'] ?? false,
            'timeframes' => array_keys($description['timeframes'] ?? []),
            'logo' => $description['urls']['logo'] ?? null,
            'required_credentials' => array_keys(array_filter($description['requiredCredentials'] ?? [])),
            'source_files' => $files,
            'source_fingerprint' => self::fingerprint($files),
        ];
    }

    public static function fingerprint(array $files): string
    {
        ksort($files);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Check source without loading generated adapter classes in a web worker. */
    public static function matches(array $files, string $root, array &$hashes): bool
    {
        if ($files === []) {
            return false;
        }
        foreach ($files as $file => $expected) {
            if (! preg_match('~^php/(?:abstract/)?[A-Za-z0-9_]+\.php$~D', $file)) {
                return false;
            }
            $path = $root.'/'.$file;
            if (! array_key_exists($path, $hashes)) {
                $hashes[$path] = is_file($path) ? hash_file('sha256', $path) : null;
            }
            if ($hashes[$path] !== $expected) {
                return false;
            }
        }

        return true;
    }
}
