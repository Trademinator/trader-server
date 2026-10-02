<?php

namespace App\Domain\Operations;

use RuntimeException;

final class TrustedProxyConfiguration
{
    /** @param list<string> $trustedProxies */
    public static function assertSafeForProduction(bool $reverseProxyEnabled, array $trustedProxies): void
    {
        if (! $reverseProxyEnabled) {
            return;
        }

        if ($trustedProxies === []) {
            throw new RuntimeException(
                'REVERSE_PROXY_ENABLED=true requires TRUSTED_PROXIES to contain explicit proxy IP addresses or CIDRs in production.'
            );
        }

        foreach ($trustedProxies as $proxy) {
            if (! self::isExplicitAddress($proxy)) {
                throw new RuntimeException(
                    'TRUSTED_PROXIES must contain only explicit proxy IP addresses or CIDRs in production; wildcards, symbolic values and hostnames are forbidden.'
                );
            }
        }
    }

    private static function isExplicitAddress(string $proxy): bool
    {
        if (filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! preg_match('/^(.+)\/(\d{1,3})$/D', $proxy, $matches)) {
            return false;
        }

        $address = $matches[1];
        $prefix = (int) $matches[2];
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefix <= 32;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefix <= 128;
        }

        return false;
    }
}
