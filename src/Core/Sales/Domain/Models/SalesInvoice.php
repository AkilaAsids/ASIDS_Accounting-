<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales invoice.
 *
 * Milestone 4 builds it as far as drafts. Only `Draft` is reachable; issuing, posting and cancellation are
 * Milestone 5, and the structural boundary between them is in the database already — see ADR 0007.
 *
 * NO SOFT DELETES
 * ---------------
 * Unlike `Customer` and `TaxCode`. A never-issued draft is not an accounting document, so deleting one removes
 * the row; an issued invoice is a statutory record and cannot be deleted at all. That is why there is no
 * `deleted_at` rather than why there should be one.
 *
 * Audited, and the lines are not. A line has no life of its own — it is created, replaced or destroyed as part
 * of its invoice — so auditing both would turn a three-line edit into four unrelated events. What the trail
 * records is the document changing, which is the question an auditor actually asks.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $customer_id
 * @property string|null $number
 * @property string|null $reference
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable $due_date
 * @property string $currency_code
 * @property numeric-string|null $exchange_rate
 * @property numeric-string $subtotal
 * @property numeric-string $discount_total
 * @property numeric-string $tax_total
 * @property numeric-string $total
 * @property numeric-string $amount_paid
 * @property numeric-string $amount_due
 * @property SalesInvoiceStatus $status
 * @property CarbonImmutable|null $issued_at
 * @property string|null $issued_by_id
 * @property string|null $journal_entry_id
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $cancelled_by_id
 * @property string|null $notes
 * @property string|null $terms
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read Customer $customer
 * @property-read Branch|null $branch
 * @property-read EloquentCollection<int, SalesInvoiceLine> $lines
 * @property-read JournalEntry|null $journalEntry
 */
final class SalesInvoice extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<SalesInvoiceFactory> */
    use HasFactory;

    use HasUuids;

    public const string MORPH_ALIAS = 'sales_invoice';

    protected $table = 'sales_invoices';

    /**
     * Only the free-text fields.
     *
     * Every figure is computed by `SalesInvoiceService` from the lines, and every date and reference is
     * validated there. A fillable `total` would let a caller write one that disagrees with the lines, which
     * the database would then refuse with a constraint name instead of a useful message.
     *
     * @var list<string>
     */
    protected $fillable = ['reference', 'notes', 'terms'];

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
     * The lines, in the order the document was entered.
     *
     * Ordered here rather than at every call site, because an invoice that reprints in a different order from
     * the one it was typed in is a different document to the person reading it.
     *
     * @return HasMany<SalesInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'sales_invoice_id')->orderBy('line_number');
    }

    /**
     * The ledger entry this invoice caused, once Milestone 5 posts it.
     *
     * Always null in Milestone 4 — a database CHECK forbids a draft from carrying one.
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
        return $this->status === SalesInvoiceStatus::Draft;
    }

    /**
     * Whether the invoice's contents may still be changed.
     *
     * Delegates to the enum so there is one definition. Milestone 5 adds the database trigger that makes this
     * impossible rather than merely refused.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Whether the invoice is past its due date and still owed.
     *
     * Derived, never stored. A stored flag would need a nightly job to stay true and would be wrong between
     * runs, and "overdue" is a question about today rather than a fact about the document.
     */
    public function isOverdue(?CarbonImmutable $asAt = null): bool
    {
        if (! $this->status->isCollectable()) {
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
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', SalesInvoiceStatus::Draft->value);
    }

    /**
     * Invoices representing a live receivable.
     *
     * Excludes drafts, which are not yet owed, and cancelled and paid ones, which no longer are. Used by the
     * reporting that arrives in Milestone 7.
     *
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function scopeCollectable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SalesInvoiceStatus::Issued->value,
            SalesInvoiceStatus::PartiallyPaid->value,
        ]);
    }

    /**
     * Columns worth recording a change to.
     *
     * The money and the dates, because those are what a customer disputes and an auditor checks. `status` is
     * here for Milestone 5, where the issuing and cancellation transitions become the most important entries
     * this trail will hold.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [
            'number',
            'reference',
            'customer_id',
            'invoice_date',
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
        return ['sales', 'invoice'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesInvoiceStatus::class,
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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
