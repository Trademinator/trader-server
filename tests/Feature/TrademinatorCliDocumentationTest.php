<?php

use Illuminate\Support\Facades\Artisan;

it('keeps the CLI reference complete and all trademinator commands lowercase', function () {
    $documentation = file_get_contents(base_path('CLI.md'));
    $commands = [];
    foreach (Artisan::all() as $command) {
        $name = $command->getName();
        if (! str_starts_with($name, 'trademinator:')) {
            continue;
        }
        $commands[$name] = $command;
        expect($name)->toMatch('/^trademinator:[a-z0-9]+(?:-[a-z0-9]+)*$/');
        foreach ($command->getAliases() as $alias) {
            expect($alias)->toMatch('/^[a-z0-9]+(?::[a-z0-9]+)?(?:-[a-z0-9]+)*$/');
        }
        $signature = (new ReflectionClass($command))->getDefaultProperties()['signature'] ?? null;
        expect($signature)->not->toBeNull();
        $signature = preg_replace('/\s+/', ' ', trim($signature));
        expect($documentation)->toContain('Signature: `'.$signature.'`')
            ->toContain('Description: '.$command->getDescription());
    }
    preg_match_all('/^## (trademinator:[^\s]+)$/m', $documentation, $matches);
    $documented = $matches[1];
    $registered = array_keys($commands);
    sort($documented);
    sort($registered);
    expect($registered)->not->toBeEmpty()->and($documented)->toBe($registered);
});

it('registers the lowercase replacements without mixed-case aliases', function () {
    $commands = Artisan::all();
    expect($commands)->toHaveKeys(['trademinator:fetch-ohlcv', 'trademinator:create-indicators'])
        ->not->toHaveKey('trademinator:FetchOHLCV')
        ->not->toHaveKey('trademinator:CreateIndicators');
    $this->artisan('help', ['command_name' => 'trademinator:fetch-ohlcv'])->assertSuccessful();
    $this->artisan('help', ['command_name' => 'trademinator:create-indicators'])->assertSuccessful();
});
