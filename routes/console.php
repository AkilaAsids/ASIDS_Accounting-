<?php

declare(strict_types=1);

use Asids\Core\Audit\Presentation\Console\PruneAuditLogCommand;
use Asids\Core\Audit\Presentation\Console\SealAuditChainCommand;
use Asids\Core\Audit\Presentation\Console\VerifyAuditChainCommand;
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

// Links the unsealed tail of each workspace's audit trail into the hash chain. Frequent, because
// the unsealed window is the period during which tampering would not yet be detectable.
Schedule::command(SealAuditChainCommand::class)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground()
    ->description('Seal the audit trail hash chain');

// Nightly assurance. A non-zero exit here means someone with database access rewrote history, and
// nothing else in the platform will tell you — wire this to an alert.
Schedule::command(VerifyAuditChainCommand::class)
    ->dailyAt('19:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->emailOutputOnFailure((string) env('SECURITY_ALERT_EMAIL', config('mail.from.address')))
    ->description('Verify audit trail integrity');

// Retention. `--confirm` is passed here because the schedule *is* the deliberate decision; run
// without it manually to see what would be deleted.
Schedule::command(PruneAuditLogCommand::class, ['--confirm'])
    ->weeklyOn(0, '20:00')
    ->onOneServer()
    ->description('Apply audit and activity retention policy');

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
