<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Models;

use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allocation of a supplier payment to a bill — the payable-side mirror of `BillLine` (ADR 0020 §C2).
 *
 * The subledger detail behind the Trade Payables control account: the payment posts one grouped
 * `Dr Trade Payables` line and the per-bill split lives here.
 *
 * Not audited, and no morph alias — deliberately. An allocation has no life of its own: it is created as part
 * of its payment and never afterwards, the payment's own audit entries record the document, and an allocation
 * can never be a `SourceDocument`. An alias registered for neither purpose is a claim that something may point
 * here (mirror `BillLine`, `Bill.php:36`, provider `PurchasingServiceProvider.php:56-57`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $supplier_payment_id
 * @property string $bill_id
 * @property numeric-string $amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read SupplierPayment $payment
 * @property-read Bill $bill
 */
final class PaymentAllocation extends Model
{
    /*
     * `BelongsToTenant` even though the parent already has it, because row level security is not transitive.
     * The allocations table carries its own `tenant_id` and its own policy, so nothing else populates it.
     */
    use BelongsToTenant;

    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_allocations';

    /**
     * Empty on purpose. Every value is computed by `PaymentService` (mirror `BillLine.php:71-76`).
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<SupplierPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }
}
