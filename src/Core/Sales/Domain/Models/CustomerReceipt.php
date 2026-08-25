<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer receipt: money arriving, allocated across the customer's issued invoices.
 *
 * The mirror of `SalesInvoice` on the receiving side. Posted-only and immutable this wave (Gate-1 #1): there
 * is no draft state, so a receipt carries its number, its posting and its `posted_at` from insert, and the
 * database trigger freezes every one of them afterwards.
 *
 * Audited, and the allocation lines are not — the same judgement `SalesInvoice` makes about its lines: a line
 * has no life of its own, so auditing both would turn one recording into several unrelated events. What the
 * trail records is the receipt, which is the question an auditor asks.
 *
 * `status` is a plain string, not an enum: only `'posted'` is reachable this wave and the column is the
 * forward-compatible boundary for a later `'cancelled'`, so nothing is gained by narrowing it to a type that
 * would have exactly one case.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $customer_id
 * @property string $number
 * @property string|null $reference
 * @property CarbonImmutable $receipt_date
 * @property string $currency_code
 * @property numeric-string $amount
 * @property PaymentMethod $payment_method
 * @property string $bank_account_id
 * @property string $status
 * @property string|null $journal_entry_id
 * @property CarbonImmutable|null $posted_at
 * @property string|null $posted_by_id
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read Customer $customer
 * @property-read Branch|null $branch
 * @property-read Account $bankAccount
 * @property-read EloquentCollection<int, ReceiptAllocation> $allocations
 * @property-read JournalEntry|null $journalEntry
 */
final class CustomerReceipt extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    public const string MORPH_ALIAS = 'customer_receipt';

    protected $table = 'customer_receipts';

    /**
     * Nothing is mass-assignable. Every field is written by `ReceiptService` from a validated `ReceiptData`,
     * and the money and the posting are computed rather than supplied — a fillable `amount` or
     * `journal_entry_id` would let a caller write a figure that disagrees with the ledger.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The asset account this receipt debited.
     *
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * The subledger detail — which invoices this receipt paid, in what amounts.
     *
     * @return HasMany<ReceiptAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class, 'customer_receipt_id');
    }

    /**
     * The one ledger entry this receipt caused.
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount, $this->currency_code);
    }

    /**
     * @param  Builder<CustomerReceipt>  $query
     * @return Builder<CustomerReceipt>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Columns worth recording a change to.
     *
     * The money, the method and the account it landed in — what a customer disputes and an auditor
     * reconciles against a bank statement. `status` is here for the deferred reversal sub-slice, where a
     * posted → cancelled transition becomes the entry this trail most needs to hold.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [
            'number',
            'reference',
            'customer_id',
            'receipt_date',
            'amount',
            'payment_method',
            'bank_account_id',
            'status',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['sales', 'receipt'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'amount' => 'decimal:4',
            'payment_method' => PaymentMethod::class,
        ];
    }
}
