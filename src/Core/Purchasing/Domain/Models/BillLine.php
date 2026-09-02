<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Models;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BillLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bill — the payable-side mirror of `SalesInvoiceLine`.
 *
 * Free text with a required expense account (Gate-1 dec. 4): what makes a line postable is the expense
 * account, which is why that is the only reference it cannot do without.
 *
 * Not audited, and no morph alias — deliberately. A line has no life of its own: it is created, replaced or
 * destroyed as part of its bill, the bill's own audit entries already record the document changing, and a line
 * can never be a `SourceDocument`. An alias registered for neither purpose is a claim that something may point
 * here.
 *
 * `tax_rate` is a snapshot, not a join. A bill posted at 18% must still read 18% after the code's rate changes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $bill_id
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
 * @property string $expense_account_id
 * @property string|null $branch_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Bill $bill
 * @property-read Account $expenseAccount
 * @property-read TaxCode|null $taxCode
 */
final class BillLine extends Model
{
    /*
     * `BelongsToTenant` even though the parent already has it, because row level security is not transitive.
     * The lines table carries its own `tenant_id` and its own policy, so nothing else will populate the column.
     */
    use BelongsToTenant;

    /** @use HasFactory<BillLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'bill_lines';

    /**
     * Empty on purpose. Every value on a line is computed or validated by `BillService`.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The expense account this line debits when the bill is posted.
     *
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /**
     * The code whose rate was snapshotted onto this line. Null for an untaxed line.
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
     * Whether this line reduces the bill rather than adding to it.
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
