<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One apply-credit event — ADR 0016 §B, §D.
 *
 * This much of a held credit reclassified onto an invoice, with its own reclassification journal entry. Each
 * application is its own journal-entry source document, which is why it is a row rather than a running total on
 * `receipt_held_credits`: the source-uniqueness index permits one non-reversing posting per source, so a second
 * apply against one held credit must carry its own source (Problem #1).
 *
 * Audited — unlike the held-credit balance and the receipt allocations, an application is a distinct financial
 * event a person triggered, and the trail records what was applied, to which invoice, from which credit, by
 * whom.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $customer_id
 * @property string $receipt_held_credit_id
 * @property string $sales_invoice_id
 * @property string $currency_code
 * @property numeric-string $amount
 * @property string $journal_entry_id
 * @property CarbonImmutable $applied_at
 * @property string|null $applied_by_id
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ReceiptHeldCredit $heldCredit
 * @property-read SalesInvoice $invoice
 * @property-read JournalEntry $journalEntry
 * @property-read Customer $customer
 */
final class CreditApplication extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    public const string MORPH_ALIAS = 'credit_application';

    protected $table = 'credit_applications';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return BelongsTo<ReceiptHeldCredit, $this>
     */
    public function heldCredit(): BelongsTo
    {
        return $this->belongsTo(ReceiptHeldCredit::class, 'receipt_held_credit_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    /**
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return ['amount', 'sales_invoice_id', 'receipt_held_credit_id', 'applied_by_id'];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['sales', 'credit-application'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
