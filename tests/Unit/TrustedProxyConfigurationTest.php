<?php

use App\Domain\Operations\TrustedProxyConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

afterEach(function (): void {
    TrustProxies::flushState();
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('requires explicit trusted proxies when reverse proxy mode is enabled in production', function () {
    TrustedProxyConfiguration::assertSafeForProduction(true, []);
})->throws(RuntimeException::class, 'REVERSE_PROXY_ENABLED=true requires TRUSTED_PROXIES');

it('rejects unsafe or ambiguous production trusted proxy entries', function (string $proxy) {
    TrustedProxyConfiguration::assertSafeForProduction(true, [$proxy]);
})->with([
    'wildcard' => '*',
    'double wildcard' => '**',
    'remote addr token' => 'REMOTE_ADDR',
    'hostname' => 'proxy.example.com',
    'invalid IPv4 CIDR' => '10.0.0.1/33',
    'invalid IPv6 CIDR' => '2001:db8::1/129',
])->throws(RuntimeException::class, 'TRUSTED_PROXIES must contain only explicit proxy IP addresses or CIDRs');

it('accepts explicit IPv4 IPv6 and CIDR proxy entries', function () {
    TrustedProxyConfiguration::assertSafeForProduction(true, [
        '127.0.0.1',
        '10.20.30.0/24',
        '2001:db8::10',
        '2001:db8:1234::/48',
    ]);

    expect(true)->toBeTrue();
});

it('does not require trusted proxies when reverse proxy mode is disabled', function () {
    TrustedProxyConfiguration::assertSafeForProduction(false, []);

    expect(true)->toBeTrue();
});

it('uses forwarded client IP only when the immediate peer is trusted', function () {
    TrustProxies::at(['10.20.30.40']);
    TrustProxies::withHeaders(
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT
    );
    $middleware = new TrustProxies;

    $trusted = Request::create('https://trademinator.test/', 'GET', [], [], [], [
        'REMOTE_ADDR' => '10.20.30.40',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
    ]);
    $trustedIp = $middleware->handle($trusted, fn (Request $request): ?string => $request->ip());

    $untrusted = Request::create('https://trademinator.test/', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.99',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.200',
    ]);
    $untrustedIp = $middleware->handle($untrusted, fn (Request $request): ?string => $request->ip());

    expect($trustedIp)->toBe('203.0.113.50')
        ->and($untrustedIp)->toBe('198.51.100.99');
});
