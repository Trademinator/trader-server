<?php

use Illuminate\Config\Repository;
use Symfony\Component\Process\Process;
use Tests\Support\TestEnvironment;

it('rejects every persistent or redirected test database before database refresh', function () {
    $safe = ['app' => ['env' => 'testing'], 'database' => ['default' => 'sqlite',
        'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => null]]]];
    foreach ([
        ['database.default', 'mariadb'], ['app.env', 'production'],
        ['database.connections.sqlite.database', '/tmp/real-database.sqlite'],
        ['database.connections.sqlite.driver', 'mysql'],
        ['database.connections.sqlite.url', 'sqlite:///tmp/real-database.sqlite'],
        ['database.connections.sqlite.read', ['database' => '/tmp/real-database.sqlite']],
        ['database.connections.sqlite.write', ['database' => '/tmp/real-database.sqlite']],
    ] as [$key, $value]) {
        $config = new Repository($safe);
        $config->set($key, $value);
        expect(fn () => TestEnvironment::guard($config))->toThrow(RuntimeException::class, 'Stopped before database refresh');
    }
    $config = new Repository($safe);
    $config->set('database.connections.mariadb', ['driver' => 'mariadb', 'database' => 'server']);
    TestEnvironment::guard($config);
    expect(array_keys($config->get('database.connections')))->toBe(['sqlite']);
});

it('leaves a persistent sentinel database and deployment cache untouched despite inherited production settings', function () {
    $directory = sys_get_temp_dir().'/trademinator-safety-probe-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $database = $directory.'/sentinel.sqlite';
    $cachedConfig = $directory.'/production-config.php';
    $pdo = new PDO('sqlite:'.$database);
    $pdo->exec('CREATE TABLE users (name TEXT)');
    $pdo->exec("INSERT INTO users (name) VALUES ('KEEP THIS USER')");
    $pdo = null;
    file_put_contents($cachedConfig, '<?php return '.var_export([
        'app' => ['env' => 'production'], 'database' => ['default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $database, 'url' => null]]],
    ], true).';');
    $databaseHash = hash_file('sha256', $database);
    $cacheHash = hash_file('sha256', $cachedConfig);
    $root = dirname(__DIR__, 2);
    $envHash = is_file($root.'/.env') ? hash_file('sha256', $root.'/.env') : null;
    $script = <<<'PHP'
require 'vendor/autoload.php';
$test = new class('testProbe') extends Tests\TestCase {
    public function testProbe(): void {}
};
$app = $test->createApplication();
if ($app['config']->get('database.default') !== 'sqlite'
    || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
    throw new RuntimeException('The test environment was not isolated.');
}
$exit = $app->make(Illuminate\Contracts\Console\Kernel::class)->call('migrate:fresh', ['--force' => true]);
if ($exit !== 0 || ! Illuminate\Support\Facades\Schema::hasTable('users')) {
    throw new RuntimeException('Isolated migration failed.');
}
echo 'ISOLATED';
PHP;
    try {
        $process = new Process([PHP_BINARY, '-r', $script], $root, [
            'APP_ENV' => 'production', 'DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => $database,
            'DB_URL' => 'sqlite:///'.$database, 'APP_CONFIG_CACHE' => $cachedConfig,
            'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'database',
        ]);
        $process->setTimeout(30)->mustRun();
        expect($process->getOutput())->toBe('ISOLATED')
            ->and(hash_file('sha256', $database))->toBe($databaseHash)
            ->and(hash_file('sha256', $cachedConfig))->toBe($cacheHash)
            ->and(is_file($root.'/.env') ? hash_file('sha256', $root.'/.env') : null)->toBe($envHash);
        $pdo = new PDO('sqlite:'.$database);
        expect($pdo->query('SELECT name FROM users')->fetchColumn())->toBe('KEEP THIS USER');
    } finally {
        $pdo = null;
        unlink($database);
        unlink($cachedConfig);
        rmdir($directory);
    }
});
