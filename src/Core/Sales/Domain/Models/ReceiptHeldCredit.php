<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The credit held from one receipt's overpayment — ADR 0016 §B.
 *
 * The mutable balance of a remainder: how much was originally held, how much has been applied, how much
 * remains. One row per receipt (Gate-1 #4), created by `ReceiptService::record()` when a receipt leaves a
 * remainder, consumed by `applyCredit()`, and unwound by `cancel()`.
 *
 * NOT `Auditable`, deliberately. Its state is fully derivable from its already-audited parents and events — the
 * receipt that created it and the credit applications that consumed it are each on the trail — so auditing the
 * balance too would record the same movement twice under two unrelated events.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $customer_id
 * @property string $customer_receipt_id
 * @property string $currency_code
 * @property numeric-string $original_amount
 * @property numeric-string $applied_amount
 * @property numeric-string $remaining_amount
 * @property string $status
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerReceipt $receipt
 * @property-read Customer $customer
 * @property-read EloquentCollection<int, CreditApplication> $applications
 */
final class ReceiptHeldCredit extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $table = 'receipt_held_credits';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The apply events that consumed this credit — many may reference one record (§B).
     *
     * @return HasMany<CreditApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CreditApplication::class, 'receipt_held_credit_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'remaining_amount' => 'decimal:4',
        ];
    }
}
