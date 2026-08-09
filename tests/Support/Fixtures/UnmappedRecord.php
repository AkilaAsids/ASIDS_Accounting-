<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document deliberately missing from every morph map.
 *
 * The counterpart to `SourceRecord`, and the only thing it exists to prove: that citing an unmapped
 * model as a ledger entry's source fails loudly.
 *
 * It has to be a separate class rather than an unregistered use of `SourceRecord`, because the morph
 * map is global and registering the alias once in a `beforeEach` leaves it registered for the rest of
 * the process. A class that is never registered anywhere is the only reliable way to exercise the
 * failure path.
 *
 * Note what it does NOT declare: no `MORPH_ALIAS`, and no entry in any service provider. Adding
 * either would quietly disarm the test that depends on it.
 */
final class UnmappedRecord extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'unmapped_records';

    /** @var list<string> */
    protected $fillable = ['reference'];

    /**
     * Creates the backing table.
     *
     * DDL inside the test transaction, which PostgreSQL makes transactional — `RefreshDatabase`'s
     * rollback takes the table with it, so no fixture table reaches a real database.
     */
    public static function createTable(): void
    {
        if (Schema::hasTable('unmapped_records')) {
            return;
        }

        Schema::create('unmapped_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference');
            $table->timestampsTz();
        });
    }
}
