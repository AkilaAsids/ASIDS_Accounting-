<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Domain\Concerns;

use Asids\Core\Audit\Infrastructure\Observers\AuditableObserver;
use Illuminate\Database\Eloquent\Model;

/**
 * Applied to a model to have every change recorded.
 *
 * Opt-in rather than universal, deliberately. Auditing everything sounds safer but produces a
 * table where most rows are session touches and `last_activity_at` updates, and an auditor
 * cannot find the invoice approval in the noise. A model earns the trait when its history is
 * something someone would ask about.
 *
 * @phpstan-require-extends Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(AuditableObserver::class);
    }

    /**
     * Columns excluded from the audit payload.
     *
     * Defaults cover the noise every model shares. Credentials are redacted centrally by
     * AuditRecorder, so they do not need listing here — this is about *volume*, not secrecy.
     *
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return [
            'updated_at',
            'last_activity_at',
            'last_seen_at',
            'last_used_at',
            'failed_login_attempts',
        ];
    }

    /**
     * Restricts the audit payload to an explicit column list. Empty means "everything except
     * `auditExcluded()`", which is the right default for most models.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [];
    }

    /**
     * Free-form labels stored on each entry, indexed with `jsonb_path_ops` so an auditor can ask
     * "show me everything tagged payroll" across seven years without a table scan.
     *
     * @return list<string>
     */
    public function auditTags(): array
    {
        return [];
    }
}
