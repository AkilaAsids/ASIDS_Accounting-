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
 * Links the unsealed tail of each workspace's audit trail into the hash chain.
 *
 * Runs every five minutes. One workspace's failure is reported and does not stop the rest: a
 * single tenant's problem must not leave the whole platform's trail unsealed.
 */
final class SealAuditChainCommand extends Command
{
    protected $signature = 'asids:audit-seal
                            {--tenant= : Seal a single workspace by id or slug}
                            {--batch=5000 : Maximum entries to seal per workspace per run}';

    protected $description = 'Seal unsealed audit entries into the tamper-evident hash chain';

    public function handle(AuditChainSealer $sealer, TenantContext $tenantContext): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $single = $this->option('tenant');

        if (is_string($single) && $single !== '') {
            return $this->sealOne($sealer, $single, $batch);
        }

        $sealed = 0;
        $failed = 0;

        // Platform-scope entries (tenant_id NULL) form their own chain and would otherwise never
        // be sealed, since they belong to no tenant.
        try {
            $sealed += $sealer->seal(null, $batch)['sealed'];
        } catch (Throwable $e) {
            $failed++;
            $this->components->error('Platform chain: '.$e->getMessage());
        }

        $tenantContext->eachActiveTenant(
            callback: function (Tenant $tenant) use ($sealer, $batch, &$sealed): void {
                $sealed += $sealer->seal((string) $tenant->getKey(), $batch)['sealed'];
            },
            onFailure: function (Tenant $tenant, Throwable $e) use (&$failed): void {
                $failed++;
                $this->components->error(sprintf('Workspace %s: %s', $tenant->slug, $e->getMessage()));
            },
        );

        $this->components->info(sprintf('%d entr%s sealed; %d workspace(s) failed.', $sealed, $sealed === 1 ? 'y' : 'ies', $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sealOne(AuditChainSealer $sealer, string $identifier, int $batch): int
    {
        // Through the repository, which knows that `id` is a uuid and a slug is not — see
        // `findByIdOrSlug`. The obvious `where('id', $x)->orWhere('slug', $x)` that used to be here
        // raised a PostgreSQL cast error for every slug, which is the usage this option documents.
        $tenant = app(TenantRepositoryContract::class)->findByIdOrSlug($identifier);

        if ($tenant === null) {
            $this->components->error("No workspace matches \"{$identifier}\".");

            return self::FAILURE;
        }

        $result = $sealer->seal((string) $tenant->getKey(), $batch);

        $this->components->info(sprintf(
            '%d entr%s sealed for %s%s.',
            $result['sealed'],
            $result['sealed'] === 1 ? 'y' : 'ies',
            $tenant->slug,
            $result['sealed'] === 0 ? '' : sprintf(' (sequence %d–%d)', $result['from_sequence'], $result['to_sequence']),
        ));

        return self::SUCCESS;
    }
}
