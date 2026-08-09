<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in for the business documents that cause ledger entries.
 *
 * Milestone 1 gives `journal_entries` a polymorphic link to the document that caused it, but the
 * first real such document — the sales invoice — does not arrive until Milestone 4. Testing the link
 * against a fixture rather than waiting keeps the two concerns separate: this suite proves the
 * mechanism, and the invoice suite will prove the invoice.
 *
 * It also means these tests do not need rewriting when invoices land. The same reasoning gave
 * `AuditedRecord` to the audit trait, and that fixture is still earning its place.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reference
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SourceRecord extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const string MORPH_ALIAS = 'source_record';

    protected $table = 'source_records';

    /** @var list<string> */
    protected $fillable = ['reference'];

    /**
     * Creates the backing table.
     *
     * DDL inside the test transaction, which works because PostgreSQL makes schema changes
     * transactional — `RefreshDatabase`'s rollback takes the table with it. A migration would put a
     * fixture table into every real database instead.
     */
    public static function createTable(): void
    {
        if (Schema::hasTable('source_records')) {
            return;
        }

        Schema::create('source_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference');
            $table->timestampsTz();
        });
    }
}
