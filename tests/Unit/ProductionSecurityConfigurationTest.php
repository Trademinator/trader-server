<?php

use App\Domain\Operations\ProductionSecurityConfiguration;
use Illuminate\Config\Repository;

function secureProductionConfiguration(array $overrides = []): Repository
{
    return new Repository(array_replace_recursive([
        'app' => ['env' => 'production', 'debug' => false, 'url' => 'https://trademinator.example'],
        'session' => ['encrypt' => true, 'secure' => true, 'http_only' => true],
        'logging' => [
            'default' => 'stack',
            'channels' => [
                'stack' => ['driver' => 'stack', 'channels' => ['single']],
                'single' => ['driver' => 'single', 'level' => 'info'],
            ],
        ],
    ], $overrides));
}

it('accepts a hardened production configuration', function () {
    (new ProductionSecurityConfiguration)->assertSafe(secureProductionConfiguration());

    expect(true)->toBeTrue();
});

it('ignores development configuration', function () {
    $config = secureProductionConfiguration([
        'app' => ['env' => 'local', 'debug' => true, 'url' => 'http://localhost'],
        'session' => ['encrypt' => false, 'secure' => false, 'http_only' => false],
        'logging' => ['channels' => ['single' => ['level' => 'debug']]],
    ]);

    (new ProductionSecurityConfiguration)->assertSafe($config);

    expect($config->get('app.debug'))->toBeTrue();
});

it('rejects unsafe production settings', function (array $override, string $message) {
    expect(fn () => (new ProductionSecurityConfiguration)->assertSafe(secureProductionConfiguration($override)))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'debug mode' => [['app' => ['debug' => true]], 'APP_DEBUG must be false'],
    'plain HTTP URL' => [['app' => ['url' => 'http://trademinator.example']], 'APP_URL must use https'],
    'unencrypted stored sessions' => [['session' => ['encrypt' => false]], 'SESSION_ENCRYPT must be true'],
    'insecure session cookie' => [['session' => ['secure' => false]], 'SESSION_SECURE_COOKIE must be true'],
    'script-readable session cookie' => [['session' => ['http_only' => false]], 'SESSION_HTTP_ONLY must be true'],
    'debug logging' => [['logging' => ['channels' => ['single' => ['level' => 'debug']]]], 'LOG_LEVEL must not be debug'],
]);

it('turns runtime debug mode off before rejecting production', function () {
    $config = secureProductionConfiguration(['app' => ['debug' => true]]);

    try {
        (new ProductionSecurityConfiguration)->assertSafe($config);
    } catch (RuntimeException) {
        // Expected.
    }

    expect($config->get('app.debug'))->toBeFalse();
});

it('ships production-safe example settings', function () {
    $env = file_get_contents(__DIR__.'/../../.env.example');

    expect($env)->toContain(
        'APP_ENV=production',
        'APP_DEBUG=false',
        'APP_URL=https://localhost',
        'LOG_LEVEL=info',
        'SESSION_ENCRYPT=true',
        'SESSION_SECURE_COOKIE=true',
        'SESSION_HTTP_ONLY=true',
        'SESSION_SAME_SITE=lax',
    );
});
