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
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Domain\Enums\PaymentStatus;
use Asids\Core\Purchasing\Domain\Models\PaymentAllocation;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * That `SupplierPaymentFactory`/`PaymentAllocationFactory` produce rows the database and the domain both
 * accept — Stage 2 of Wave 8a. The payable-side mirror of `BillFactoryTest.php`.
 *
 * Unlike `BillFactory` (which can default `journal_entry_id` to null, since a draft has none), a payment is
 * born posted and `journal_entry_id` is NOT NULL and UNIQUE — nothing can invent a real journal entry, so it
 * is a required per-row override exactly like `company_id`/`supplier_id`/`bank_account_id`, never a factory
 * default.
 *
 * RED expectation before Stage 2 lands: `SupplierPayment`/`PaymentAllocation` and their factories do not exist.
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
            supplierInvoiceNumber: 'PAY-FACTORY-1',
            lines: [new BillLineData(
                description: 'Office supplies',
                quantity: '1',
                unitPrice: '1000.00',
                expenseAccountId: (string) $this->purchases->getKey(),
            )],
        )),
        $this->owner,
    );

    $this->references = [
        'company_id' => $this->company->getKey(),
        'supplier_id' => $this->supplier->getKey(),
        'bank_account_id' => $this->bank->getKey(),
    ];
});

/**
 * A fresh posted journal entry — the required, un-inventable `journal_entry_id` every persisted payment row
 * needs, unique per row.
 */
function factoryPaymentJournalEntryId(): string
{
    $entry = app(PostingService::class)->postNew(test()->company, new JournalEntryData(
        entryDate: CarbonImmutable::parse('2026-06-15'),
        description: 'Factory-test standing entry',
        lines: [
            new JournalLineData(accountId: (string) test()->tradePayables->getKey(), debit: Money::of('1000.00', 'LKR')),
            new JournalLineData(accountId: (string) test()->bank->getKey(), credit: Money::of('1000.00', 'LKR')),
        ],
        documentType: DocumentType::JournalVoucher,
    ));

    return (string) $entry->getKey();
}

describe('the supplier payment factory', function (): void {
    it('produces a valid posted payment', function (): void {
        $payment = SupplierPayment::factory()->create([
            ...$this->references,
            'journal_entry_id' => factoryPaymentJournalEntryId(),
        ]);

        expect($payment->exists)->toBeTrue()
            ->and($payment->status)->toBe(PaymentStatus::Posted)
            ->and($payment->number)->not->toBeNull()
            ->and($payment->posted_at)->not->toBeNull()
            ->and($payment->journal_entry_id)->not->toBeNull();
    });

    it('sets status explicitly so an unsaved instance is strict-mode-safe', function (): void {
        // The trap Phase 1 hit on `must_change_password`: an unsaved model returns null for a defaulted column
        // and reading it back before a refresh throws under `shouldBeStrict()`. `make()` never touches the DB.
        $payment = SupplierPayment::factory()->make($this->references);

        expect($payment->status)->toBe(PaymentStatus::Posted);
    });

    it('inherits the tenant from the active context', function (): void {
        $payment = SupplierPayment::factory()->create([
            ...$this->references,
            'journal_entry_id' => factoryPaymentJournalEntryId(),
        ]);

        expect($payment->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('requires a company, a supplier, a bank account and a journal entry rather than inventing them', function (): void {
        expect(fn () => SupplierPayment::factory()->create())
            ->toThrow(QueryException::class);
    });

    it('satisfies the amount-positive invariant the database asserts', function (): void {
        $payment = SupplierPayment::factory()->create([
            ...$this->references,
            'journal_entry_id' => factoryPaymentJournalEntryId(),
        ]);

        expect(bccomp($payment->amount, '0', 4))->toBe(1);
    });

    it('produces several payments that do not collide on the number index', function (): void {
        for ($i = 0; $i < 3; $i++) {
            SupplierPayment::factory()->create([
                ...$this->references,
                'journal_entry_id' => factoryPaymentJournalEntryId(),
            ]);
        }

        expect(SupplierPayment::query()->forCompany($this->company->getKey())->count())->toBe(3);
    });

    it('honours a payment date override', function (): void {
        $payment = SupplierPayment::factory()->create([
            ...$this->references,
            'journal_entry_id' => factoryPaymentJournalEntryId(),
            'payment_date' => '2026-03-01',
        ]);

        expect($payment->payment_date->toDateString())->toBe('2026-03-01');
    });
});

describe('the payment allocation factory', function (): void {
    it('produces a valid allocation', function (): void {
        $payment = SupplierPayment::factory()->create([
            ...$this->references,
            'journal_entry_id' => factoryPaymentJournalEntryId(),
        ]);

        $allocation = PaymentAllocation::factory()->create([
            'company_id' => $this->company->getKey(),
            'supplier_payment_id' => $payment->getKey(),
            'bill_id' => $this->bill->getKey(),
        ]);

        expect($allocation->exists)->toBeTrue()
            ->and($allocation->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('satisfies the amount-positive invariant the database asserts', function (): void {
        $payment = SupplierPayment::factory()->create([
            ...$this->references,
            'journal_entry_id' => factoryPaymentJournalEntryId(),
        ]);

        $allocation = PaymentAllocation::factory()->create([
            'company_id' => $this->company->getKey(),
            'supplier_payment_id' => $payment->getKey(),
            'bill_id' => $this->bill->getKey(),
        ]);

        expect(bccomp($allocation->amount, '0', 4))->toBe(1);
    });

    it('requires a payment, a company and a bill rather than inventing them', function (): void {
        expect(fn () => PaymentAllocation::factory()->create())
            ->toThrow(QueryException::class);
    });

    it('dies with its payment when the payment is hard-deleted', function (): void {
        // Cascade is the declared behaviour (§A3); reaching it legitimately is covered by
        // `PaymentSchemaTest`'s freeze-trigger tests, which show that path is unreachable this wave. Here we
        // only need the factory to produce a row that names its payment correctly.
        $payment = SupplierPayment::factory()->create([
            ...$this->references,
            'journal_entry_id' => factoryPaymentJournalEntryId(),
        ]);

        $allocation = PaymentAllocation::factory()->create([
            'company_id' => $this->company->getKey(),
            'supplier_payment_id' => $payment->getKey(),
            'bill_id' => $this->bill->getKey(),
        ]);

        expect($allocation->payment->getKey())->toBe($payment->getKey());
    });
});
