<?php

namespace App\Domain\Operations;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final class ProductionSecurityConfiguration
{
    public function assertSafe(Repository $config): void
    {
        if ($config->get('app.env') !== 'production') {
            return;
        }

        $errors = [];
        if ($config->get('app.debug') === true) {
            // Fail closed without rendering Laravel's detailed production exception page.
            $config->set('app.debug', false);
            $errors[] = 'APP_DEBUG must be false';
        }

        $url = (string) $config->get('app.url', '');
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            $errors[] = 'APP_URL must use https';
        }
        if ($config->get('session.encrypt') !== true) {
            $errors[] = 'SESSION_ENCRYPT must be true';
        }
        if ($config->get('session.secure') !== true) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true';
        }
        if ($config->get('session.http_only') !== true) {
            $errors[] = 'SESSION_HTTP_ONLY must be true';
        }
        if ($this->activeLogLevelsContainDebug($config)) {
            $errors[] = 'LOG_LEVEL must not be debug';
        }

        if ($errors !== []) {
            throw new RuntimeException('Unsafe production configuration: '.implode('; ', $errors).'.');
        }
    }

    private function activeLogLevelsContainDebug(Repository $config): bool
    {
        $channels = $config->get('logging.channels', []);
        if (! is_array($channels)) {
            return false;
        }

        $seen = [];
        $levels = $this->channelLevels($channels, (string) $config->get('logging.default', ''), $seen);

        return in_array('debug', $levels, true);
    }

    /**
     * @param  array<string, mixed>  $channels
     * @param  array<string, bool>  $seen
     * @return list<string>
     */
    private function channelLevels(array $channels, string $name, array &$seen): array
    {
        if ($name === '' || isset($seen[$name]) || ! isset($channels[$name]) || ! is_array($channels[$name])) {
            return [];
        }
        $seen[$name] = true;
        $channel = $channels[$name];
        $levels = [];
        if (isset($channel['level']) && is_string($channel['level'])) {
            $levels[] = strtolower($channel['level']);
        }
        if (($channel['driver'] ?? null) === 'stack' && isset($channel['channels']) && is_array($channel['channels'])) {
            foreach ($channel['channels'] as $child) {
                if (is_string($child)) {
                    $levels = [...$levels, ...$this->channelLevels($channels, $child, $seen)];
                }
            }
        }

        return $levels;
    }
}
