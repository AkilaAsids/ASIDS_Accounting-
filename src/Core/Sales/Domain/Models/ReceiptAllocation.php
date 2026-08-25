<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a receipt's allocation: this much of the receipt applied to that invoice.
 *
 * The subledger detail, not a ledger posting — the receipt as a whole posts once. Immutable once written (its
 * own trigger, because immutability is not transitive), and not audited for the same reason `SalesInvoiceLine`
 * is not: it is part of the receipt whose recording the trail already captures.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $customer_receipt_id
 * @property string $sales_invoice_id
 * @property numeric-string $amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerReceipt $receipt
 * @property-read SalesInvoice $invoice
 */
final class ReceiptAllocation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'receipt_allocations';

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
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function amountMoney(string $currency): Money
    {
        return Money::of($this->amount, $currency);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }
}
