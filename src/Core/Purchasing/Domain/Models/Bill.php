<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Models;

use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill (purchase invoice) — the payable-side mirror of `SalesInvoice`.
 *
 * Wave 7 builds drafts and posts them; cancellation and payments are later, and the structural boundary is in
 * the database already — see ADR 0019.
 *
 * NO SOFT DELETES
 * ---------------
 * A never-posted draft is not an accounting document, so deleting one removes the row; a posted bill is a
 * statutory record and cannot be deleted at all. That is why there is no `deleted_at`.
 *
 * Audited, and the lines are not. A line has no life of its own — it is created, replaced or destroyed as part
 * of its bill — so auditing both would turn a three-line edit into four unrelated events.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $supplier_id
 * @property string $supplier_invoice_number
 * @property string|null $number
 * @property CarbonImmutable $bill_date
 * @property CarbonImmutable $due_date
 * @property string $currency_code
 * @property numeric-string|null $exchange_rate
 * @property numeric-string $subtotal
 * @property numeric-string $discount_total
 * @property numeric-string $tax_total
 * @property numeric-string $total
 * @property numeric-string $amount_paid
 * @property numeric-string $amount_due
 * @property BillStatus $status
 * @property CarbonImmutable|null $posted_at
 * @property string|null $posted_by_id
 * @property string|null $journal_entry_id
 * @property string|null $notes
 * @property string|null $terms
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read Supplier $supplier
 * @property-read Branch|null $branch
 * @property-read EloquentCollection<int, BillLine> $lines
 * @property-read JournalEntry|null $journalEntry
 */
final class Bill extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<BillFactory> */
    use HasFactory;

    use HasUuids;

    public const string MORPH_ALIAS = 'bill';

    protected $table = 'bills';

    /**
     * Only the free-text fields.
     *
     * Every figure and identifier is computed or validated by `BillService`. A fillable
     * `supplier_invoice_number`, `number` or `total` would let a caller write one the guards then refuse with
     * a constraint name instead of a useful message.
     *
     * @var list<string>
     */
    protected $fillable = ['notes', 'terms'];

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
     * The lines, in the order the document was entered.
     *
     * @return HasMany<BillLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class, 'bill_id')->orderBy('line_number');
    }

    /**
     * The ledger entry this bill caused, once posted.
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

    public function isDraft(): bool
    {
        return $this->status === BillStatus::Draft;
    }

    /**
     * Whether the bill's contents may still be changed. Delegates to the enum so there is one definition.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Whether the bill is past its due date and still owed.
     *
     * Derived, never stored — "overdue" is a question about today rather than a fact about the document.
     */
    public function isOverdue(?CarbonImmutable $asAt = null): bool
    {
        if (! $this->status->isOutstanding()) {
            return false;
        }

        return $this->due_date->startOfDay()->lessThan(($asAt ?? CarbonImmutable::now())->startOfDay());
    }

    public function subtotalMoney(): Money
    {
        return Money::of($this->subtotal, $this->currency_code);
    }

    public function taxTotalMoney(): Money
    {
        return Money::of($this->tax_total, $this->currency_code);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency_code);
    }

    public function amountDueMoney(): Money
    {
        return Money::of($this->amount_due, $this->currency_code);
    }

    /**
     * @param  Builder<Bill>  $query
     * @return Builder<Bill>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<Bill>  $query
     * @return Builder<Bill>
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', BillStatus::Draft->value);
    }

    /**
     * Bills representing a live payable.
     *
     * Excludes drafts, which are not yet owed, and cancelled and paid ones, which no longer are. The payable
     * balance probe's source of truth.
     *
     * @param  Builder<Bill>  $query
     * @return Builder<Bill>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BillStatus::Posted->value,
            BillStatus::PartiallyPaid->value,
        ]);
    }

    /**
     * Columns worth recording a change to.
     *
     * The money and the dates, plus the two identifiers — the supplier's invoice number, because it is the
     * statutory identity a supplier disputes, and the internal `number`. `status` records the posting
     * transition.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [
            'number',
            'supplier_invoice_number',
            'supplier_id',
            'bill_date',
            'due_date',
            'subtotal',
            'discount_total',
            'tax_total',
            'total',
            'status',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['purchasing', 'bill'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BillStatus::class,
            'bill_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'exchange_rate' => 'decimal:10',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'amount_due' => 'decimal:4',
        ];
    }
}
