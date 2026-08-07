<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in for the business documents the `Auditable` trait exists to serve.
 *
 * No Phase 1 model applies the trait: the security-relevant changes of this phase are captured by
 * the eleven domain-event listeners in AuditServiceProvider instead, and the trait is meant for
 * the invoices, journals and payments that arrive with Accounting. That left it in the worst
 * possible state — shipped, but exercised by nothing and, because PHPStan does not analyse a trait
 * no class uses, not even type-checked.
 *
 * This fixture closes both gaps. It is a real model with the trait applied, so the observer wiring,
 * the column filtering and the tagging are all executed against a real table, and the trait is
 * analysed like any other code. When Accounting adds its first genuinely audited model, this stops
 * being the only consumer but stays useful: it exercises the trait's behaviour without depending
 * on any particular document's schema.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reference
 * @property string $note
 * @property numeric-string $amount
 * @property string|null $internal_state
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class AuditedRecord extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    /**
     * The morph alias this model is registered under. Kept as a constant so the test asserting
     * that the audit trail stores the alias rather than the class name cannot drift from the
     * registration itself.
     */
    public const string MORPH_ALIAS = 'audited_record';

    protected $table = 'audited_records';

    /** @var list<string> */
    protected $fillable = ['reference', 'note', 'amount', 'internal_state', 'last_seen_at'];

    /**
     * Creates the backing table.
     *
     * DDL inside the test transaction is deliberate and works because PostgreSQL makes schema
     * changes transactional — RefreshDatabase's rollback removes the table along with the rows. A
     * migration would put a fixture table into every real database instead.
     */
    public static function createTable(): void
    {
        Schema::create('audited_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference');
            $table->string('note');
            $table->decimal('amount', 18, 2)->default(0);
            // Excluded from the audit payload by `auditOnly()`, so a change to it must produce no
            // entry — the property that distinguishes filtering from not recording at all.
            $table->string('internal_state')->nullable();
            // Covered by the trait's default exclusions.
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    /**
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return ['reference', 'note', 'amount'];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['fixture', 'ledger'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
