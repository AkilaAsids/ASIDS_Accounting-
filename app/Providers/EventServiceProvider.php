<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Framework-wide event wiring.
 *
 * Domain events and their listeners are registered by the module that owns them;
 * this provider covers only infrastructure events that belong to no module.
 */
final class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A failed job in this system may be a failed audit write or a failed
        // statutory filing, so it is logged at error level with enough context to
        // replay it rather than being left to the `failed_jobs` table alone.
        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            Log::error('Queued job failed.', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'attempts' => $event->job->attempts(),
                'uuid' => $event->job->uuid(),
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);
        });
    }
}
