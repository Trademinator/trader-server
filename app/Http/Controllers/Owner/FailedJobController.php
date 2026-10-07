<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Operations\ActionLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

final class FailedJobController extends Controller
{
    public function __construct(private FailedJobProviderInterface $failedJobs) {}

    public function show(string $job): View
    {
        $failedJob = $this->failedJobs->find($job);
        abort_if($failedJob === null, 404);

        $payload = json_decode($failedJob->payload, true);
        $jobName = data_get($payload, 'displayName') ?? data_get($payload, 'data.commandName');

        return view('owner.failed-job', [
            'job' => $failedJob,
            'jobName' => is_string($jobName) ? $jobName : 'Unknown job',
            'failureReason' => Str::before($failedJob->exception, "\n"),
        ]);
    }

    public function retry(string $job, ActionLog $log): RedirectResponse
    {
        return $this->perform($job, true, $log);
    }

    public function destroy(string $job, ActionLog $log): RedirectResponse
    {
        return $this->perform($job, false, $log);
    }

    private function perform(string $job, bool $requeue, ActionLog $log): RedirectResponse
    {
        $response = Cache::lock('owner:failed-job:'.$job, 300)->get(function () use ($job, $requeue, $log): RedirectResponse {
            $failedJob = $this->failedJobs->find($job);
            if ($failedJob === null) {
                return to_route('owner.queue-backlog')->withErrors([
                    'failed_job' => 'This failed job no longer exists. It may already have been requeued or discarded.',
                ]);
            }

            if ($requeue && ! in_array(config('queue.connections.'.$failedJob->connection.'.driver'), ['database', 'redis', 'beanstalkd', 'sqs'], true)) {
                return to_route('owner.queue-backlog')->withErrors([
                    'failed_job' => 'This job’s original connection is not configured as a supported background queue. The failure record was kept.',
                ]);
            }

            try {
                if ($requeue) {
                    $payload = json_decode($failedJob->payload, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($payload) || ! is_string($payload['job'] ?? null) || $payload['job'] === '') {
                        throw new RuntimeException('The failed job payload is invalid.');
                    }
                    $exitCode = Artisan::call('queue:retry', ['id' => [$job], '--no-interaction' => true]);
                    if ($exitCode !== 0) {
                        throw new RuntimeException('The failed job could not be requeued.');
                    }
                } elseif (! $this->failedJobs->forget($job)) {
                    return to_route('owner.queue-backlog')->withErrors([
                        'failed_job' => 'This failed job no longer exists. It may already have been requeued or discarded.',
                    ]);
                }
            } catch (Throwable $error) {
                report($error);

                return to_route('owner.queue-backlog')->withErrors([
                    'failed_job' => $requeue
                        ? 'The job could not be requeued. Check the server log for details before trying again.'
                        : 'The failure record could not be discarded. Check the server log for details before trying again.',
                ]);
            }

            $log->write($requeue ? 'owner.failed_job.requeue' : 'owner.failed_job.discard', [
                'job_id' => $job, 'queue' => $failedJob->queue, 'outcome' => 'completed',
            ]);

            return to_route('owner.queue-backlog')->with('status', $requeue
                ? 'Job '.$job.' requeued on '.$failedJob->connection.' / '.$failedJob->queue.'.'
                : 'Failed job '.$job.' discarded without running it.');
        });

        return $response ?: to_route('owner.queue-backlog')->withErrors([
            'failed_job' => 'Another action is already in progress for this failed job. Refresh the backlog before trying again.',
        ]);
    }
}
