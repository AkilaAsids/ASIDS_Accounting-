<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Domain\Enums\PaymentStatus;
use Asids\Core\Purchasing\Domain\Models\PaymentAllocation;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `PaymentStatus`, `DocumentType::Payment`, and the `SupplierPayment`/`PaymentAllocation` models — Stage 2 of
 * Wave 8a (ADR 0020 §C1, §C2, §D).
 *
 * The payable-side mirror of `BillModelTest.php`, minus everything the born-posted lifecycle has no analogue
 * for: no `isDraft`/`isEditable`, no `scopeDrafts`, no due-date/overdue vocabulary. `PaymentStatus` reserves
 * `Cancelled` unreachable this wave, exactly as `BillStatus` shipped all five states before four were
 * reachable.
 *
 * Models are built by direct attribute assignment, never `::create()` — only `notes` is fillable on
 * `SupplierPayment` and nothing is fillable on `PaymentAllocation` (§C2), so a mass-assigning `::create()`
 * would throw `MassAssignmentException` for every other column, which is precisely the guard §A7 relies on.
 *
 * RED expectation before Stage 2 lands: `PaymentStatus`, `SupplierPayment`, `PaymentAllocation` and
 * `DocumentType::Payment` do not exist, and the morph alias is unregistered — every test errors on a missing
 * class/case or the null morph map.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();
    $this->tradePayables = Account::query()->forCompany($this->company->getKey())->where('code', '2110')->firstOrFail();

    $this->bill = app(BillService::class)->post(
        app(BillService::class)->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'PAY-MODEL-1',
            lines: [new BillLineData(
                description: 'Office supplies',
                quantity: '1',
                unitPrice: '1000.00',
                expenseAccountId: (string) $this->purchases->getKey(),
            )],
        )),
        $this->owner,
    );
});

/**
 * A fresh posted journal entry to hang a `SupplierPayment` fixture's `journal_entry_id` off — nothing
 * generates one, exactly as nothing generates `company_id`/`supplier_id`/`bank_account_id`.
 */
function modelPaymentJournalEntryId(): string
{
    $entry = app(PostingService::class)->postNew(test()->company, new JournalEntryData(
        entryDate: CarbonImmutable::parse('2026-06-15'),
        description: 'Model-test standing entry',
        lines: [
            new JournalLineData(accountId: (string) test()->tradePayables->getKey(), debit: Money::of('1000.00', 'LKR')),
            new JournalLineData(accountId: (string) test()->bank->getKey(), credit: Money::of('1000.00', 'LKR')),
        ],
        documentType: DocumentType::JournalVoucher,
    ));

    return (string) $entry->getKey();
}

/**
 * A fully-formed, saved `SupplierPayment`, built by direct attribute assignment.
 *
 * @param  array<string, mixed>  $overrides
 */
function modelPayment(array $overrides = []): SupplierPayment
{
    $payment = new SupplierPayment;

    $payment->company_id = test()->company->getKey();
    $payment->supplier_id = test()->supplier->getKey();
    $payment->branch_id = null;
    $payment->bank_account_id = test()->bank->getKey();
    $payment->number = 'PAY-'.Str::random(10);
    $payment->payment_date = CarbonImmutable::parse('2026-06-15');
    $payment->currency_code = 'LKR';
    $payment->exchange_rate = null;
    $payment->amount = '1000.0000';
    $payment->status = PaymentStatus::Posted;
    $payment->reference = null;
    $payment->notes = null;
    $payment->journal_entry_id = modelPaymentJournalEntryId();
    $payment->posted_at = now();
    $payment->posted_by_id = null;
    $payment->created_by_id = null;

    foreach ($overrides as $key => $value) {
        $payment->setAttribute($key, $value);
    }

    $payment->save();

    return $payment;
}

/**
 * A fully-formed, saved `PaymentAllocation`, built by direct attribute assignment.
 *
 * @param  array<string, mixed>  $overrides
 */
function modelAllocation(string $paymentId, string $billId, array $overrides = []): PaymentAllocation
{
    $allocation = new PaymentAllocation;

    $allocation->company_id = test()->company->getKey();
    $allocation->supplier_payment_id = $paymentId;
    $allocation->bill_id = $billId;
    $allocation->amount = '1000.0000';

    foreach ($overrides as $key => $value) {
        $allocation->setAttribute($key, $value);
    }

    $allocation->save();

    return $allocation;
}

describe('the PaymentStatus enum', function (): void {
    it('declares both cases, only Posted reachable this wave', function (): void {
        expect(PaymentStatus::Posted->value)->toBe('posted')
            ->and(PaymentStatus::Cancelled->value)->toBe('cancelled');
    });

    it('labels each case', function (): void {
        expect(PaymentStatus::Posted->label())->toBe('Posted')
            ->and(PaymentStatus::Cancelled->label())->toBe('Cancelled');
    });

    it('is posted only for the Posted case', function (): void {
        expect(PaymentStatus::Posted->isPosted())->toBeTrue()
            ->and(PaymentStatus::Cancelled->isPosted())->toBeFalse();
    });

    it('is cancelled only for the Cancelled case', function (): void {
        expect(PaymentStatus::Cancelled->isCancelled())->toBeTrue()
            ->and(PaymentStatus::Posted->isCancelled())->toBeFalse();
    });
});

describe('DocumentType::Payment', function (): void {
    it('carries the PAY prefix and requires gapless numbering', function (): void {
        // The second gapless-originated document (dec. 4, ADR §D): a payment is originated by us, not
        // received, which is the exact hinge that made `Bill` the sole non-gapless case.
        expect(DocumentType::Payment->value)->toBe('payment')
            ->and(DocumentType::Payment->prefix())->toBe('PAY')
            ->and(DocumentType::Payment->label())->toBe('Payment')
            ->and(DocumentType::Payment->requiresGaplessNumbering())->toBeTrue();
    });

    it('leaves Bill the sole non-gapless case', function (): void {
        expect(DocumentType::Bill->requiresGaplessNumbering())->toBeFalse();
    });

    it('leaves every other existing family gapless', function (): void {
        expect(DocumentType::JournalVoucher->requiresGaplessNumbering())->toBeTrue()
            ->and(DocumentType::OpeningBalance->requiresGaplessNumbering())->toBeTrue()
            ->and(DocumentType::YearEndClose->requiresGaplessNumbering())->toBeTrue()
            ->and(DocumentType::SalesInvoice->requiresGaplessNumbering())->toBeTrue();
    });

    it('adds exactly one case', function (): void {
        expect(DocumentType::cases())->toHaveCount(6);
    });
});

describe('the SupplierPayment model', function (): void {
    it('casts status, dates and money', function (): void {
        $payment = modelPayment(['number' => 'PAY-CAST-1']);

        expect($payment->status)->toBeInstanceOf(PaymentStatus::class)
            ->and($payment->status)->toBe(PaymentStatus::Posted)
            ->and($payment->payment_date)->toBeInstanceOf(CarbonImmutable::class)
            ->and($payment->posted_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and($payment->amount)->toBe('1000.0000');
    });

    it('exposes the supplier_payment morph alias and round-trips through the enforced map', function (): void {
        // `SupplierPayment` is `Auditable`; an audit entry for an unmapped class throws, so the alias must be
        // registered in the provider boot. `'supplier_payment'`, not `'payment'`, leaves room for a future
        // `customer_receipt` (§C2).
        expect(SupplierPayment::MORPH_ALIAS)->toBe('supplier_payment')
            ->and(Relation::getMorphedModel(SupplierPayment::MORPH_ALIAS))->toBe(SupplierPayment::class);
    });

    it('relates to its company, supplier, bank account, allocations and journal entry', function (): void {
        $payment = modelPayment(['number' => 'PAY-RELATE-1']);
        modelAllocation((string) $payment->getKey(), (string) $this->bill->getKey());

        $fresh = $payment->fresh(['company', 'supplier', 'bankAccount', 'allocations', 'journalEntry']);

        expect($fresh->company->getKey())->toBe($this->company->getKey())
            ->and($fresh->supplier->getKey())->toBe($this->supplier->getKey())
            ->and($fresh->bankAccount->getKey())->toBe($this->bank->getKey())
            ->and($fresh->allocations)->toHaveCount(1)
            ->and($fresh->allocations->first()->bill_id)->toBe((string) $this->bill->getKey())
            ->and($fresh->journalEntry->getKey())->toBe($payment->journal_entry_id);
    });

    it('relates to a branch when one is named', function (): void {
        $branch = Branch::factory()->create(['company_id' => $this->company->getKey()]);

        $payment = modelPayment(['number' => 'PAY-BRANCH-1', 'branch_id' => $branch->getKey()]);

        expect($payment->fresh('branch')->branch->getKey())->toBe($branch->getKey());
    });

    it('relates to the user who posted and who created it', function (): void {
        $payment = modelPayment([
            'number' => 'PAY-USERS-1',
            'posted_by_id' => $this->owner->getKey(),
            'created_by_id' => $this->owner->getKey(),
        ]);

        expect($payment->fresh('postedBy')->postedBy?->getKey() ?? null)->not->toBeNull();
        expect($payment->fresh('createdBy')->createdBy?->getKey() ?? null)->not->toBeNull();
    });

    it('scopes to a company', function (): void {
        modelPayment(['number' => 'PAY-SCOPE-1']);

        expect(SupplierPayment::query()->forCompany($this->company->getKey())->count())->toBe(1);
    });
});

describe('the SupplierPayment fillable and audit surface', function (): void {
    it('only lets notes be mass-assigned', function (): void {
        // Every figure and identifier is service-computed — a fillable `number` or `amount` would let a caller
        // write one the guards then refuse with a constraint name instead of a useful message.
        $fillable = (new SupplierPayment)->getFillable();

        expect($fillable)->toBe(['notes'])
            ->and($fillable)->not->toContain('number')
            ->and($fillable)->not->toContain('amount')
            ->and($fillable)->not->toContain('status');
    });

    it('audits the change-worthy columns', function (): void {
        $auditOnly = (new SupplierPayment)->auditOnly();

        expect($auditOnly)->toContain('number')
            ->and($auditOnly)->toContain('supplier_id')
            ->and($auditOnly)->toContain('bank_account_id')
            ->and($auditOnly)->toContain('payment_date')
            ->and($auditOnly)->toContain('amount')
            ->and($auditOnly)->toContain('reference')
            ->and($auditOnly)->toContain('status');
    });

    it('tags audit entries for the purchasing payment domain', function (): void {
        expect((new SupplierPayment)->auditTags())->toBe(['purchasing', 'payment']);
    });
});

describe('the PaymentAllocation model', function (): void {
    it('has no morph alias and is not independently mapped', function (): void {
        // An allocation has no life of its own and is never a source document — registering an alias would
        // claim something may point at it (mirror `BillLine`, ADR §C2).
        expect(defined(PaymentAllocation::class.'::MORPH_ALIAS'))->toBeFalse()
            ->and(Relation::getMorphedModel('payment_allocation'))->toBeNull();
    });

    it('mass-assigns nothing', function (): void {
        expect((new PaymentAllocation)->getFillable())->toBe([]);
    });

    it('relates an allocation to its payment and its bill', function (): void {
        $payment = modelPayment(['number' => 'PAY-ALLOC-REL-1']);
        $allocation = modelAllocation((string) $payment->getKey(), (string) $this->bill->getKey());

        expect($allocation->payment->getKey())->toBe($payment->getKey())
            ->and($allocation->bill->getKey())->toBe($this->bill->getKey())
            ->and($allocation->amount)->toBe('1000.0000');
    });
});

describe('a cancelled payment', function (): void {
    it('casts a cancelled payment correctly', function (): void {
        $payment = modelPayment(['number' => 'PAY-CANCEL-1']);

        // The posted → cancelled transition the freeze trigger permits (§A5), writing the cancellation metadata
        // the CHECK requires alongside the status (Wave 8c, ADR 0023).
        DB::table('supplier_payments')->where('id', $payment->getKey())->update([
            'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'recorded in error', 'updated_at' => now(),
        ]);

        $fresh = $payment->fresh();

        expect($fresh->status)->toBe(PaymentStatus::Cancelled)
            ->and($fresh->status->isCancelled())->toBeTrue()
            ->and($fresh->status->isPosted())->toBeFalse()
            ->and($fresh->cancellation_reason)->toBe('recorded in error')
            ->and($fresh->cancelled_at)->not->toBeNull();
    });
});
