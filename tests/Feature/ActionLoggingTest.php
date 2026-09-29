<?php

use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\ObservedTestJob;

function captureOperationsLog(): TestHandler
{
    $handler = new TestHandler;
    app()->instance(ActionLog::class, new ActionLog(new Logger('test-actions', [$handler]), app(ActionContext::class)));

    return $handler;
}

function operationRecords(TestHandler $handler, string $event): array
{
    return array_values(array_filter(array_map(fn ($record) => json_decode($record->message, true), $handler->getRecords()),
        fn ($record) => $record['event'] === $event));
}

it('allows syslog to be disabled without breaking requests', function () {
    config(['operations.syslog_enabled' => false]);
    app()->forgetInstance(ActionLog::class);

    $this->get('/')->assertOk()->assertHeader('X-Trademinator-Trace');
});

it('logs committed record changes and discards rolled back changes', function () {
    $handler = captureOperationsLog();
    $created = null;
    DB::transaction(function () use (&$created): void {
        $created = User::factory()->create();
    });
    try {
        DB::transaction(function (): void {
            User::factory()->create();
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    $records = operationRecords($handler, 'record.created');
    expect($records)->toHaveCount(1);
    expect($records[0])->toMatchArray(['source' => User::class, 'subject_id' => $created->user_id, 'outcome' => 'committed']);
    expect(json_encode($handler->getRecords()))->not->toContain($created->email, $created->name, $created->password);
});

it('writes one JSON line and discards confidential or injected context', function () {
    $user = User::factory()->create();
    $handler = captureOperationsLog();
    app(ActionLog::class)->write('test.completed', ['subject_id' => $user->user_id, 'rows' => 3,
        'password' => 'VERY_PRIVATE', 'email' => $user->email, 'api_key' => 'PRIVATE_KEY',
        'payload' => ['secret' => 'VERY_PRIVATE'], 'exception' => new RuntimeException('VERY_PRIVATE'),
        'route' => "fake\ninjected", 'symbol' => "BTC/USD\nforged", 'duration_ms' => 15]);

    $record = $handler->getRecords()[0];
    $line = (new LineFormatter('%message%', allowInlineLineBreaks: false))->format($record);
    expect($line)->not->toContain("\n", "\r", 'VERY_PRIVATE', 'PRIVATE_KEY', $user->email, 'forged', 'injected');
    expect(json_decode($line, true))->toMatchArray(['event' => 'test.completed', 'subject_id' => $user->user_id, 'rows' => 3, 'duration_ms' => 15]);
});

it('links failed requests and safe exceptions without logging URL secrets', function () {
    $handler = captureOperationsLog();
    Route::get('/_operations-failure/{token}', fn () => throw new RuntimeException('VERY_PRIVATE'))->name('test.failure');

    $response = $this->get('/_operations-failure/PRIVATE_TOKEN?api_key=PRIVATE_KEY')->assertInternalServerError();

    $http = operationRecords($handler, 'http.completed');
    $errors = operationRecords($handler, 'exception.reported');
    expect($http)->toHaveCount(1);
    expect($errors)->toHaveCount(1);
    expect($http[0])->toMatchArray(['route' => 'test.failure', 'status_code' => 500, 'outcome' => 'failed']);
    expect($errors[0]['trace_id'])->toBe($http[0]['trace_id']);
    $response->assertHeader('X-Trademinator-Trace', $http[0]['trace_id']);
    expect(json_encode($handler->getRecords()))->not->toContain('VERY_PRIVATE', 'PRIVATE_TOKEN', 'PRIVATE_KEY');
});

it('preserves the response and trace when authentication cannot be resolved', function () {
    $handler = captureOperationsLog();
    Route::get('/_operations-unavailable', function (Request $request) {
        $request->setUserResolver(fn () => throw new RuntimeException('VERY_PRIVATE'));

        return response('', 503);
    })->name('test.unavailable');

    $response = $this->get('/_operations-unavailable')->assertStatus(503);

    $http = operationRecords($handler, 'http.completed');
    expect($http)->toHaveCount(1);
    expect($http[0])->toMatchArray(['route' => 'test.unavailable', 'status_code' => 503, 'outcome' => 'failed']);
    $response->assertHeader('X-Trademinator-Trace', $http[0]['trace_id']);
    expect(json_encode($handler->getRecords()))->not->toContain('VERY_PRIVATE');
    expect(app(ActionContext::class)->current())->toBe([]);
});

it('does not carry an authenticated actor into the next guest request', function () {
    $handler = captureOperationsLog();
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk();
    auth()->forgetGuards();
    $this->get('/')->assertOk();

    $records = operationRecords($handler, 'http.completed');
    expect($records[0]['actor_id'])->toBe($user->user_id);
    expect($records[1])->not->toHaveKey('actor_id');
    expect($records[0]['trace_id'])->not->toBe($records[1]['trace_id']);
});

it('logs command exit status without its arguments', function () {
    // Laravel disables Symfony command lifecycle events during tests; exercise the production event bridge.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
    $handler = captureOperationsLog();
    Artisan::command('ops:test {password}', function () {
        return 7;
    });

    expect(Artisan::call('ops:test', ['password' => 'VERY_PRIVATE']))->toBe(7);

    $start = operationRecords($handler, 'command.started')[0];
    $end = operationRecords($handler, 'command.completed')[0];
    expect($end)->toMatchArray(['command' => 'ops:test', 'exit_code' => 7, 'outcome' => 'failed', 'trace_id' => $start['trace_id']]);
    expect(json_encode($handler->getRecords()))->not->toContain('VERY_PRIVATE');
    expect(app(ActionContext::class)->current())->toBe([]);
});

it('links jobs to their parent and records the actual attempt outcome', function (string $mode, string $outcome) {
    $handler = captureOperationsLog();
    $parent = app(ActionContext::class)->begin();
    Queue::connection('database')->push(new ObservedTestJob('VERY_PRIVATE', $mode));
    app(ActionContext::class)->end($parent);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]);

    $queued = operationRecords($handler, 'job.queued');
    $started = operationRecords($handler, 'job.started');
    $finished = operationRecords($handler, 'job.completed');
    expect($queued)->toHaveCount(1);
    expect($finished)->toHaveCount(1);
    expect($started[0]['parent_trace_id'])->toBe($parent);
    expect($finished[0])->toMatchArray(['trace_id' => $started[0]['trace_id'], 'outcome' => $outcome, 'attempt' => 1]);
    expect($finished[0]['job_id'])->toBe($queued[0]['job_id']);
    expect(json_encode($handler->getRecords()))->not->toContain('VERY_PRIVATE');
    expect(app(ActionContext::class)->current())->toBe([]);
})->with(['completed' => ['success', 'completed'], 'released' => ['release', 'released'], 'failed' => ['fail', 'failed']]);
