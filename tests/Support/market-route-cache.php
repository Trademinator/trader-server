<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\RouteCollection;
use Tests\TestCase;

$probe = new class('testProbe') extends TestCase
{
    public function test_probe(): void {}
};
$app = $probe->createApplication();
$cache = $app->getCachedRoutesPath();
if (! str_starts_with($cache, sys_get_temp_dir().'/trademinator-tests-')) {
    throw new RuntimeException('Refusing to access any deployment route cache.');
}
$directory = dirname($cache);
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$names = ['markets.suggestions', 'markets.suggestions.review', 'markets.preferences.store', 'markets.preferences.destroy'];
try {
    $old = new RouteCollection;
    foreach ($app['router']->getRoutes() as $route) {
        if (! in_array($route->getName(), $names, true)) {
            $route->prepareForSerialization();
            $old->add($route);
        }
    }
    file_put_contents($cache, '<?php app(\'router\')->setCompiledRoutes('.var_export($old->compile(), true).');');
    $app = $probe->createApplication();
    if (! $app->routesAreCached() || $app['router']->has('markets.suggestions')) {
        throw new RuntimeException('The stale cache fixture was not loaded.');
    }
    $app->make(Kernel::class)->call('route:clear');
    $app = $probe->createApplication();
    if ($app->routesAreCached() || ! $app['router']->has($names)) {
        throw new RuntimeException('Clearing the isolated cache did not restore the source routes.');
    }
    if ($app->make(Kernel::class)->call('route:cache') !== 0) {
        throw new RuntimeException('Unable to rebuild the isolated route cache.');
    }
    $app = $probe->createApplication();
    if (! $app->routesAreCached() || ! $app['router']->has($names)) {
        throw new RuntimeException('Suggestion routes are missing from the rebuilt route cache.');
    }
    foreach ($names as $name) {
        $route = $app['router']->getRoutes()->getByName($name);
        if (! in_array('auth', $route->gatherMiddleware(), true)) {
            throw new RuntimeException('Cached suggestion route lost authentication.');
        }
    }
    echo json_encode(['stale_cache_reproduced' => true, 'route_clear_restored_routes' => true,
        'rebuilt_cache_restored_routes' => true, 'authentication_preserved' => true,
        'database' => $app['config']->get('database.connections.sqlite.database')], JSON_THROW_ON_ERROR);
} finally {
    if (is_file($cache)) {
        unlink($cache);
    }
    rmdir($directory);
}
