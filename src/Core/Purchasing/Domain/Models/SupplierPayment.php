<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Models;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Purchasing\Domain\Enums\PaymentStatus;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier payment — cash paid out to a supplier and allocated across their outstanding bills (ADR 0020 §C2).
 *
 * The payable-side settlement, the mirror of `Bill` with the money flow inverted (a bill *creates* a payable,
 * a payment *settles* it) and the draft machinery removed: a payment is *born posted* (Gate-1 dec. 5).
 *
 * NO SOFT DELETES
 * ---------------
 * A payment is a statutory record and cannot be deleted at all (the freeze trigger refuses it, §A5), so there
 * is no `deleted_at` — mirror `Bill.php:31-34`.
 *
 * Audited, and the allocations are not. An allocation has no life of its own — it is created as part of its
 * payment and never afterwards — so auditing both would turn one payment into two unrelated events (mirror
 * `Bill.php:36-37`, `BillLine`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $supplier_id
 * @property string $bank_account_id
 * @property string $number
 * @property CarbonImmutable $payment_date
 * @property string $currency_code
 * @property numeric-string|null $exchange_rate
 * @property numeric-string $amount
 * @property numeric-string $wht_amount
 * @property numeric-string|null $wht_rate
 * @property PaymentStatus $status
 * @property string|null $reference
 * @property string|null $notes
 * @property string $journal_entry_id
 * @property CarbonImmutable $posted_at
 * @property string|null $posted_by_id
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read Supplier $supplier
 * @property-read Branch|null $branch
 * @property-read Account $bankAccount
 * @property-read EloquentCollection<int, PaymentAllocation> $allocations
 * @property-read JournalEntry $journalEntry
 */
final class SupplierPayment extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * `'supplier_payment'`, not `'payment'`, leaves room for a future `customer_receipt`. Required, not
     * decoration: `SourceDocument::for()` round-trips this alias and `Auditable` throws for an unmapped class
     * (§C2). Registered in `PurchasingServiceProvider::boot()`.
     */
    public const string MORPH_ALIAS = 'supplier_payment';

    protected $table = 'supplier_payments';

    /**
     * Only the free-text field.
     *
     * Every figure and identifier is computed by `PaymentService`. A fillable `number` or `amount` would let
     * a caller write one the guards then refuse with a constraint name instead of a useful message — mirror
     * `Bill.php:85-94`.
     *
     * @var list<string>
     */
    protected $fillable = ['notes'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The caller-named account the payment credited (Gate-1 dec. 1).
     *
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * How this payment was split across the bills it settled.
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'supplier_payment_id');
    }

    /**
     * The ledger entry this payment posted.
     *
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount, $this->currency_code);
    }

    /**
     * @param  Builder<SupplierPayment>  $query
     * @return Builder<SupplierPayment>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Columns worth recording a change to.
     *
     * The money and the date, plus the identifiers and the status transition — mirror `Bill::auditOnly()`
     * (`Bill.php:239-253`).
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [
            'number',
            'supplier_id',
            'bank_account_id',
            'payment_date',
            'amount',
            'wht_amount',
            'wht_rate',
            'reference',
            'status',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['purchasing', 'payment'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'payment_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'exchange_rate' => 'decimal:10',
            'amount' => 'decimal:4',
            'wht_amount' => 'decimal:4',
            'wht_rate' => 'decimal:2',
        ];
    }
}
