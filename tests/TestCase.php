<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestEnvironment;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        TestEnvironment::prepare();
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            TestEnvironment::guard($app['config']);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Keep test credentials local and require explicit fixtures for external APIs.
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }
}
