<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure\Bootstrappers;

use Illuminate\Filesystem\FilesystemManager;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

/**
 * Roots every configured disk inside the active tenant's own prefix.
 *
 * With this in place, `Storage::disk('documents')->put('invoices/INV-1.pdf', …)`
 * writes to `tenants/{tenant}/invoices/INV-1.pdf`. Application code never mentions
 * the tenant, so it cannot get it wrong, and a path traversal in a filename
 * cannot escape the tenant prefix because the prefix is applied by the driver
 * rather than concatenated by the caller.
 */
final class FilesystemBootstrapper implements TenancyBootstrapper
{
    /** @var array<string, string|null> */
    private array $originalRoots = [];

    /** @var array<string, string|null> */
    private array $originalPrefixes = [];

    public function __construct(private readonly FilesystemManager $filesystem) {}

    public function bootstrap(Tenant $tenant): void
    {
        $tenantKey = (string) $tenant->getTenantKey();
        $suffixBase = (string) config('tenancy.filesystem.suffix_base', 'tenants/');

        /** @var list<string> $disks */
        $disks = config('tenancy.filesystem.disks', []);

        /** @var array<string, string> $rootOverrides */
        $rootOverrides = config('tenancy.filesystem.root_override', []);

        foreach ($disks as $disk) {
            $configKey = "filesystems.disks.{$disk}";

            if (config($configKey) === null) {
                continue;
            }

            $this->originalRoots[$disk] ??= config("{$configKey}.root");
            $this->originalPrefixes[$disk] ??= config("{$configKey}.prefix");

            if (isset($rootOverrides[$disk])) {
                // Local disks are re-rooted, which also stops a traversal from
                // reaching another tenant's directory.
                config([
                    "{$configKey}.root" => str_replace(
                        ['%storage_path%', '%tenant_id%'],
                        [storage_path(), $tenantKey],
                        $rootOverrides[$disk],
                    ),
                ]);
            } else {
                // Object stores are prefixed: one bucket, one lifecycle policy,
                // per-tenant key space.
                config([
                    "{$configKey}.prefix" => $suffixBase.$tenantKey,
                ]);
            }

            $this->filesystem->forgetDisk($disk);
        }
    }

    public function revert(): void
    {
        foreach ($this->originalRoots as $disk => $root) {
            config(["filesystems.disks.{$disk}.root" => $root]);
            config(["filesystems.disks.{$disk}.prefix" => $this->originalPrefixes[$disk] ?? null]);
            $this->filesystem->forgetDisk($disk);
        }

        $this->originalRoots = [];
        $this->originalPrefixes = [];
    }
}
