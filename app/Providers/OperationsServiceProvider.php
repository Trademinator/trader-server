<?php

namespace App\Providers;

use App\Domain\Operations\AccessStatistics;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Domain\Operations\RecordObserver;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketPreferenceProfile;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Auth\Events;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Throwable;

class OperationsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ActionContext::class);
        $this->app->singleton(ActionLog::class, function ($app): ActionLog {
            $handler = config('operations.syslog_enabled')
                ? new SyslogHandler(config('operations.syslog_ident'), config('operations.syslog_facility'))
                : new NullHandler;
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new LineFormatter('%message%', allowInlineLineBreaks: false));
            }

            // An isolated logger cannot inherit request bodies, SQL bindings or shared Laravel log context.
            return new ActionLog(new Logger('actions', [$handler]), $app->make(ActionContext::class));
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('manage-server', fn (User $user): bool => $user->isOwner() && $user->suspended_at === null);
        Gate::define('train-intelligence', fn (User $user): bool => config('human_training.enabled')
            && $user->suspended_at === null && $user->hasVerifiedEmail()
            && ($user->isOwner() || in_array(strtolower($user->user_id), array_map('strtolower', config('human_training.trainer_uuids')), true)));
        TrustProxies::at(config('operations.trusted_proxies'));
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        $this->observeRequests();
        $this->observeCommands();
        $this->observeJobs();
        $this->observeAuthentication();
        foreach ([User::class, Exchange::class, Market::class,
            MarketSubscription::class, MarketPreferenceProfile::class,
            CoinGeckoMarketMapping::class] as $model) {
            $model::observe(RecordObserver::class);
        }
    }

    private function observeRequests(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            $context = app(ActionContext::class);
            $trace = $event->request->attributes->get('trademinator_trace');
            if (! is_string($trace)) {
                return;
            }
            $log = app(ActionLog::class);
            try {
                $status = $event->response->getStatusCode();
                $duration = $context->elapsed();
                try {
                    $context->actor($event->request->user()?->getAuthIdentifier());
                } catch (Throwable $error) {
                    $log->write('auth.resolve', ['outcome' => 'failed', ...$log->exception($error)]);
                }
                $route = $event->request->route();
                $log->write('http.completed', ['route' => $route?->getName() ?? $route?->uri() ?? 'unmatched',
                    'method' => $event->request->method(), 'status_code' => $status,
                    'outcome' => $status >= 500 ? 'failed' : ($status >= 400 ? 'rejected' : 'completed'),
                    'duration_ms' => $duration]);
                $event->response->headers->set('X-Trademinator-Trace', $trace);
                try {
                    app(AccessStatistics::class)->record($event->request, $status, $duration);
                } catch (Throwable $error) {
                    $log->write('access.record', ['outcome' => 'failed', ...$log->exception($error)]);
                }
            } finally {
                $context->end($trace);
            }
        });
    }

    private function observeCommands(): void
    {
        $frames = [];
        Event::listen(CommandStarting::class, function (CommandStarting $event) use (&$frames): void {
            $frames[spl_object_id($event->input)] = app(ActionContext::class)->begin();
            app(ActionLog::class)->write('command.started', ['command' => $event->command]);
        });
        Event::listen(CommandFinished::class, function (CommandFinished $event) use (&$frames): void {
            $context = app(ActionContext::class);
            app(ActionLog::class)->write('command.completed', ['command' => $event->command,
                'exit_code' => $event->exitCode, 'outcome' => $event->exitCode === 0 ? 'completed' : 'failed',
                'duration_ms' => $context->elapsed()]);
            $key = spl_object_id($event->input);
            if (isset($frames[$key])) {
                $context->end($frames[$key]);
                unset($frames[$key]);
            }
        });
    }

    public static function queueContext(mixed $connection, mixed $queue, array $payload): array
    {
        return ['trademinator_parent_trace' => app(ActionContext::class)->current()['trace_id'] ?? null];
    }

    private function observeJobs(): void
    {
        Queue::createPayloadUsing([self::class, 'queueContext']);
        $frames = [];
        Event::listen(JobQueued::class, function (JobQueued $event): void {
            app(ActionLog::class)->write('job.queued', ['job' => is_object($event->job) ? get_class($event->job) : 'unknown',
                'job_id' => $event->payload()['uuid'] ?? null, 'queue' => $event->queue ?? 'default']);
        });
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$frames): void {
            $frames[spl_object_id($event->job)] = app(ActionContext::class)->begin($event->job->payload()['trademinator_parent_trace'] ?? null);
            app(ActionLog::class)->write('job.started', ['job' => $event->job->resolveName(),
                'job_id' => $event->job->uuid(), 'attempt' => $event->job->attempts(), 'queue' => $event->job->getQueue() ?? 'default']);
        });
        Event::listen(JobAttempted::class, function (JobAttempted $event) use (&$frames): void {
            $context = app(ActionContext::class);
            $log = app(ActionLog::class);
            $log->write('job.completed', ['job' => $event->job->resolveName(), 'job_id' => $event->job->uuid(),
                'attempt' => $event->job->attempts(), 'queue' => $event->job->getQueue() ?? 'default',
                'outcome' => $event->job->hasFailed() ? 'failed' : ($event->exception !== null ? 'error' : ($event->job->isReleased() ? 'released' : 'completed')),
                'duration_ms' => $context->elapsed(), ...($event->exception === null ? [] : $log->exception($event->exception))]);
            $key = spl_object_id($event->job);
            if (isset($frames[$key])) {
                $context->end($frames[$key]);
                unset($frames[$key]);
            }
        });
        Event::listen(JobTimedOut::class, function (JobTimedOut $event): void {
            app(ActionLog::class)->write('job.timeout', ['job' => $event->job->resolveName(),
                'job_id' => $event->job->uuid(), 'outcome' => 'timeout']);
        });
    }

    private function observeAuthentication(): void
    {
        Event::listen(Events\Authenticated::class, fn (Events\Authenticated $event) => app(ActionContext::class)->actor($event->user->getAuthIdentifier()));
        foreach ([Events\Login::class => 'auth.login', Events\Logout::class => 'auth.logout',
            Events\Failed::class => 'auth.failed', Events\Registered::class => 'auth.registered',
            Events\Verified::class => 'auth.verified', Events\PasswordReset::class => 'auth.password_reset',
            Events\Lockout::class => 'auth.lockout'] as $class => $name) {
            Event::listen($class, function (object $event) use ($name): void {
                $user = $event->user ?? null;
                if ($event instanceof Events\Login && $user instanceof User) {
                    $user->forceFill(['last_login_at' => now()])->saveQuietly();
                }
                app(ActionContext::class)->actor($user?->getAuthIdentifier());
                app(ActionLog::class)->write($name, ['subject_id' => $user?->getAuthIdentifier()]);
            });
        }
    }
}
