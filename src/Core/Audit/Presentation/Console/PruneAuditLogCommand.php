<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Presentation\Console;

use Asids\Core\Audit\Domain\Models\ActivityLog;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the retention policy.
 *
 * Two very different retentions, which is why one command handles both explicitly rather than a
 * single sweep:
 *
 *   audit_logs     Seven years by default (Sri Lankan record-keeping expectations for accounting
 *                  records). Deleting requires announcing itself to the database trigger, and
 *                  **breaks the hash chain by design** — so the command refuses without
 *                  `--confirm` and reports the new chain origin afterwards.
 *
 *   activity_logs  Ninety days. A dashboard feed, not a record; ordinary DELETE.
 */
final class PruneAuditLogCommand extends Command
{
    protected $signature = 'asids:audit-prune
                            {--confirm : Actually delete. Without this the command only reports.}
                            {--activity-only : Prune the activity feed and leave the audit trail alone}';

    protected $description = 'Delete audit and activity entries past their retention period';

    public function handle(): int
    {
        $activityCutoff = now()->subDays((int) config('asids.audit.activity_retention_days', 90));
        $auditCutoff = now()->subDays((int) config('asids.audit.retention_days', 2555));

        $activityCount = ActivityLog::query()->withoutGlobalScopes()->where('created_at', '<', $activityCutoff)->count();

        $auditCount = (int) RowLevelSecurity::bypass(
            static fn (): int => DB::table('audit_logs')->where('created_at', '<', $auditCutoff)->count()
        );

        $this->line(sprintf('  activity_logs  %d entr%s older than %s', $activityCount, $activityCount === 1 ? 'y' : 'ies', $activityCutoff->toDateString()));
        $this->line(sprintf('  audit_logs     %d entr%s older than %s', $auditCount, $auditCount === 1 ? 'y' : 'ies', $auditCutoff->toDateString()));
        $this->newLine();

        if (! $this->option('confirm')) {
            $this->components->warn('Dry run. Re-run with --confirm to delete.');

            return self::SUCCESS;
        }

        ActivityLog::query()->withoutGlobalScopes()->where('created_at', '<', $activityCutoff)->delete();
        $this->components->info(sprintf('%d activity entr%s deleted.', $activityCount, $activityCount === 1 ? 'y' : 'ies'));

        if ($this->option('activity-only') || $auditCount === 0) {
            return self::SUCCESS;
        }

        RowLevelSecurity::bypass(function () use ($auditCutoff): void {
            DB::transaction(function () use ($auditCutoff): void {
                // The trigger refuses DELETE unless this is set, so a stray query or an ORM bug
                // can never remove an audit entry — only this command can.
                DB::statement("SELECT set_config('asids.audit_prune', 'on', true)");
                DB::table('audit_logs')->where('created_at', '<', $auditCutoff)->delete();
            });
        });

        $this->components->info(sprintf('%d audit entr%s deleted.', $auditCount, $auditCount === 1 ? 'y' : 'ies'));

        // Said explicitly, because an auditor running `audit-verify` afterwards would otherwise
        // read a legitimate retention deletion as evidence of tampering.
        $this->components->warn(
            'Pruning removes the oldest links, so `asids:audit-verify` must now be run with '
            .'--from set to the earliest remaining sequence. Record this pruning in your '
            .'retention log as the new chain origin.'
        );

        return self::SUCCESS;
    }
}
