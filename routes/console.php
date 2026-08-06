<?php

declare(strict_types=1);

use Asids\Core\Identity\Presentation\Console\RevokeExpiredTokensCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Everything here runs on the `scheduler` container (see docker-compose.yml), which runs
| `schedule:work` as a single replica. `onOneServer` is applied regardless, so scaling the
| scheduler to two replicas later cannot double-execute a sweep.
|
| Times are UTC. The maintenance window is chosen for the primary market: 19:00 UTC is
| half past midnight in Colombo, comfortably outside business hours.
|
*/

// Marks tokens whose expiry has passed as revoked, so the reason is recorded rather than
// inferred from a null comparison at every authentication.
Schedule::command(RevokeExpiredTokensCommand::class)
    ->dailyAt('19:15')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground()
    ->description('Revoke expired personal access tokens');

// Laravel's own sweep of expired framework caches and failed job records.
Schedule::command('queue:prune-failed', ['--hours=336'])
    ->weeklyOn(0, '19:30')
    ->onOneServer()
    ->description('Prune failed jobs older than 14 days');

Schedule::command('cache:prune-stale-tags')
    ->hourly()
    ->onOneServer()
    ->description('Prune stale cache tags');

// Horizon retains metrics indefinitely without this.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->description('Capture Horizon queue metrics');
