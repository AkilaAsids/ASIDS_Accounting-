<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Presentation\Console;

use Asids\Core\Audit\Application\Services\AuditChainSealer;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verifies that the audit trail has not been altered.
 *
 * Intended for three situations: a scheduled nightly assurance run, an auditor's request for
 * evidence, and incident response. A non-zero exit is the signal — wire it to an alert, because a
 * broken chain means someone with database access has rewritten history, and nothing else in the
 * platform will tell you.
 */
final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'asids:audit-verify
                            {--tenant= : Verify a single workspace by id or slug}
                            {--from= : Start from this sequence number rather than the beginning}';

    protected $description = 'Verify the integrity of the audit trail hash chain';

    public function handle(AuditChainSealer $sealer, TenantContext $tenantContext): int
    {
        $from = $this->option('from') === null ? null : (int) $this->option('from');
        $single = $this->option('tenant');

        if (is_string($single) && $single !== '') {
            // See `findByIdOrSlug`: comparing a slug against the uuid `id` column is a cast error,
            // not a non-match, so this option crashed for exactly the input it advertises.
            $tenant = app(TenantRepositoryContract::class)->findByIdOrSlug($single);

            if ($tenant === null) {
                $this->components->error("No workspace matches \"{$single}\".");

                return self::FAILURE;
            }

            return $this->report($tenant->slug, $sealer->verify((string) $tenant->getKey(), $from))
                ? self::SUCCESS
                : self::FAILURE;
        }

        $allIntact = $this->report('platform', $sealer->verify(null, $from));
        $totalVerified = 0;

        $tenantContext->eachActiveTenant(
            callback: function (Tenant $tenant) use ($sealer, $from, &$allIntact, &$totalVerified): void {
                $result = $sealer->verify((string) $tenant->getKey(), $from);
                $totalVerified += $result['verified'];

                if (! $this->report($tenant->slug, $result)) {
                    $allIntact = false;
                }
            },
            onFailure: function (Tenant $tenant, Throwable $e) use (&$allIntact): void {
                $allIntact = false;
                $this->components->error(sprintf('Workspace %s could not be verified: %s', $tenant->slug, $e->getMessage()));
            },
        );

        $this->newLine();

        if ($allIntact) {
            $this->components->info(sprintf('Audit trail intact across all workspaces (%d entries verified).', $totalVerified));

            return self::SUCCESS;
        }

        $this->components->error('AUDIT TRAIL INTEGRITY FAILURE. Preserve a backup and investigate immediately.');

        return self::FAILURE;
    }

    /**
     * @param  array{verified: int, intact: bool, failure: array<string, mixed>|null}  $result
     */
    private function report(string $label, array $result): bool
    {
        if ($result['intact']) {
            $this->line(sprintf('  <fg=green>OK</>    %-24s %d entries', $label, $result['verified']));

            return true;
        }

        /** @var array<string, mixed> $failure */
        $failure = $result['failure'];

        $this->line(sprintf('  <fg=red>BROKEN</> %-24s after %d entries', $label, $result['verified']));
        $this->line(sprintf('         kind      %s', (string) $failure['kind']));
        $this->line(sprintf('         detail    %s', (string) $failure['detail']));
        $this->line(sprintf('         sequence  %s', (string) $failure['sequence']));
        $this->line(sprintf('         entry id  %s', (string) $failure['id']));

        return false;
    }
}
