<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SalesInvoiceLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sales invoice.
 *
 * Free text with a required revenue account — there is no product catalogue, per ADR 0007 decision A1. What
 * makes a line postable is the revenue account, which is why that is the only reference it cannot do without.
 *
 * Not audited, and that is deliberate rather than an omission. A line has no life of its own: it is created,
 * replaced or destroyed as part of its invoice, and the invoice's own audit entries already record the
 * document changing. Auditing lines as well would produce a trail where a three-line edit reads as four
 * unrelated events.
 *
 * `tax_rate` is a snapshot, not a join. An invoice issued at 18% must still read 18% after the code's rate
 * changes — ADR 0006 made a rate change a new row precisely so history survives, and a line resolving its
 * rate afresh on every read would defeat that.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $sales_invoice_id
 * @property int $line_number
 * @property string $description
 * @property numeric-string $quantity
 * @property numeric-string $unit_price
 * @property numeric-string|null $discount_percent
 * @property numeric-string|null $discount_amount
 * @property numeric-string $line_subtotal
 * @property string|null $tax_code_id
 * @property numeric-string $tax_rate
 * @property numeric-string $tax_amount
 * @property numeric-string $line_total
 * @property string $revenue_account_id
 * @property string|null $branch_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read SalesInvoice $invoice
 * @property-read Account $revenueAccount
 * @property-read TaxCode|null $taxCode
 */
final class SalesInvoiceLine extends Model
{
    /*
     * `BelongsToTenant` even though the parent already has it, because row level security is not transitive.
     * The lines table carries its own `tenant_id` and its own policy, so nothing else will populate the column
     * — omitting the trait produced an insert with a null `tenant_id` that the policy correctly refused, which
     * is exactly the failure the policy exists to catch. `JournalLine` does the same for the same reason.
     */
    use BelongsToTenant;

    /** @use HasFactory<SalesInvoiceLineFactory> */
    use HasFactory;

    use HasUuids;

    public const string MORPH_ALIAS = 'sales_invoice_line';

    protected $table = 'sales_invoice_lines';

    /**
     * Empty on purpose.
     *
     * Every value on a line is computed or validated by `SalesInvoiceService` — the subtotal from quantity and
     * price, the tax from the resolved rate, the total from both. Mass assignment would let a caller write a
     * `line_total` that disagrees with its parts, which the database would then refuse with a constraint name
     * rather than a useful message.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The income account this line credits when the invoice is posted.
     *
     * @return BelongsTo<Account, $this>
     */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    /**
     * The code whose rate was snapshotted onto this line.
     *
     * Null for an untaxed line. Present for traceability — which code produced this rate — and never used to
     * recompute the rate, which is what the snapshot exists to prevent.
     *
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The line's net amount, as money in the given currency.
     *
     * The currency is passed in rather than read from the line, because a line does not carry one — the
     * invoice does, and a line quoting a different currency from its header would be a bug the type system
     * should not help express.
     */
    public function subtotalMoney(string $currency): Money
    {
        return Money::of($this->line_subtotal, $currency);
    }

    public function taxMoney(string $currency): Money
    {
        return Money::of($this->tax_amount, $currency);
    }

    public function totalMoney(string $currency): Money
    {
        return Money::of($this->line_total, $currency);
    }

    /**
     * Whether this line reduces the invoice rather than adding to it.
     *
     * A negative quantity is how a correction is expressed on an otherwise positive invoice. Worth asking
     * about explicitly, because a header discount cannot be allocated across an invoice containing one.
     */
    public function isCredit(): bool
    {
        return bccomp($this->line_subtotal, '0', Money::SCALE) < 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Every monetary and quantity column as a decimal string at the ledger's scale. Never a float:
            // 0.1 is not representable in binary, and a line total that disagrees with its parts by a
            // hundredth is a line the database will refuse.
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'line_number' => 'integer',
        ];
    }
}
