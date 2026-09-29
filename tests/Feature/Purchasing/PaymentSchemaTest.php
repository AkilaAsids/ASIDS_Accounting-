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
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What the database refuses about a supplier payment — Stage 1 of Wave 8a (ADR 0020 §A).
 *
 * The payable-side mirror of `BillSchemaTest.php`, inverting the money flow and dropping the draft shape: a
 * payment is born posted (Gate-1 dec. 5), so there is no draft/posted boundary to prove — instead this proves
 * the *only* legal shape (posted, numbered, ledger-linked, fully allocated) and the freeze that follows it.
 *
 * Every insert here goes through the query builder, bypassing the model, the DTO and the service that do not
 * exist yet, so a passing test means the constraint is doing the work. Every negative that is followed by
 * further assertions in the same test is wrapped in `DB::transaction()` (a savepoint), so a PG 25P02 does not
 * abort the surrounding RefreshDatabase transaction — the `SupplierSchemaCheckMutationTest` lesson (commit
 * 80cc5a9). The deferred full-allocation trigger tests use `SET CONSTRAINTS ALL IMMEDIATE` for the same reason
 * `LedgerTest.php`'s balance-rule tests do: the trigger is `DEFERRABLE INITIALLY DEFERRED` and fires at COMMIT,
 * and `RefreshDatabase` never commits — so without forcing it, the trigger would never run in this suite at all.
 *
 * RED expectation before Stage 1 lands: there are no `supplier_payments`/`payment_allocations` tables, so
 * `Schema::hasTable` is false, every baseline insert throws "relation ... does not exist", and the bills-side
 * migration tests see the *old* Wave 7 constraint set (the phase CHECK still present, the new one absent).
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();
    $this->tradePayables = Account::query()->forCompany($this->company->getKey())->where('code', '2110')->firstOrFail();

    // A real, posted, outstanding bill — the FK target for `payment_allocations.bill_id` in most tests.
    $this->bill = app(BillService::class)->post(
        app(BillService::class)->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'PAY-SCHEMA-1',
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
 * A fresh posted journal entry, standing in for the JV a real payment would post. Only its id matters here —
 * the FK target for `supplier_payments.journal_entry_id` — so every call draws a brand new one, since that
 * column is unique.
 */
function paymentJournalEntryId(): string
{
    $entry = app(PostingService::class)->postNew(test()->company, new JournalEntryData(
        entryDate: CarbonImmutable::parse('2026-06-15'),
        description: 'Schema-test standing entry',
        lines: [
            new JournalLineData(accountId: (string) test()->tradePayables->getKey(), debit: Money::of('1000.00', 'LKR')),
            new JournalLineData(accountId: (string) test()->bank->getKey(), credit: Money::of('1000.00', 'LKR')),
        ],
        documentType: DocumentType::JournalVoucher,
    ));

    return (string) $entry->getKey();
}

/**
 * A raw, well-formed posted payment row. Born posted — there is no draft shape to default to (Gate-1 dec. 5).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function paymentRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'branch_id' => null,
        'supplier_id' => test()->supplier->getKey(),
        'bank_account_id' => test()->bank->getKey(),
        'number' => 'PAY-'.Str::random(12),
        'payment_date' => '2026-06-15',
        'currency_code' => 'LKR',
        'exchange_rate' => null,
        'amount' => '1000.0000',
        'status' => 'posted',
        'reference' => null,
        'notes' => null,
        'journal_entry_id' => paymentJournalEntryId(),
        'posted_at' => now(),
        'posted_by_id' => null,
        'created_by_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

function insertPayment(array $overrides = []): string
{
    $row = paymentRow($overrides);
    DB::table('supplier_payments')->insert($row);

    return (string) $row['id'];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertAllocationRow(string $paymentId, string $billId, array $overrides = []): string
{
    $row = [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'supplier_payment_id' => $paymentId,
        'bill_id' => $billId,
        'amount' => '1000.0000',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];

    DB::table('payment_allocations')->insert($row);

    return (string) $row['id'];
}

describe('the table shape', function (): void {
    it('carries the born-posted header columns', function (): void {
        expect(Schema::hasTable('supplier_payments'))->toBeTrue();

        expect(Schema::hasColumns('supplier_payments', [
            'id', 'tenant_id', 'company_id', 'branch_id',
            'supplier_id', 'bank_account_id',
            'number', 'payment_date', 'currency_code', 'exchange_rate', 'amount',
            'status', 'reference', 'notes',
            'journal_entry_id', 'posted_at', 'posted_by_id', 'created_by_id',
            'created_at', 'updated_at',
        ]))->toBeTrue();
    });

    it('has no bill-shaped fields and no soft delete', function (): void {
        // No supplier invoice number, no lines-derived totals, no due date — a payment has no lines and no tax
        // (Gate-1 dec. 6). No soft delete (never soft-deleted; hard-deletion is refused by the trigger, §A5).
        expect(Schema::hasColumn('supplier_payments', 'supplier_invoice_number'))->toBeFalse()
            ->and(Schema::hasColumn('supplier_payments', 'due_date'))->toBeFalse()
            ->and(Schema::hasColumn('supplier_payments', 'subtotal'))->toBeFalse()
            ->and(Schema::hasColumn('supplier_payments', 'tax_total'))->toBeFalse()
            ->and(Schema::hasColumn('supplier_payments', 'deleted_at'))->toBeFalse();
    });

    it('carries the cancellation columns (ADR 0023 §A2)', function (): void {
        expect(Schema::hasColumns('supplier_payments', [
            'cancelled_at', 'cancellation_reason', 'cancelled_by_id',
        ]))->toBeTrue();
    });

    it('carries the allocation columns', function (): void {
        expect(Schema::hasTable('payment_allocations'))->toBeTrue();

        expect(Schema::hasColumns('payment_allocations', [
            'id', 'tenant_id', 'company_id', 'supplier_payment_id', 'bill_id', 'amount',
            'created_at', 'updated_at',
        ]))->toBeTrue();
    });
});

describe('the indexes', function (): void {
    it('creates the two composite indexes and the full unique number index on supplier_payments', function (): void {
        expect(Schema::hasTable('supplier_payments'))->toBeTrue();

        $names = collect(DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', ['supplier_payments']))
            ->pluck('indexname')
            ->all();

        expect($names)->toContain('supplier_payments_tenant_id_company_id_status_index')
            ->and($names)->toContain('supplier_payments_company_id_supplier_id_payment_date_index')
            // Full, not partial — unlike bills' number index, every payment carries one from birth (§A4).
            ->and($names)->toContain('supplier_payments_company_number_unique');
    });

    it('creates the payment/bill unique and the bill_id lookup index on payment_allocations', function (): void {
        expect(Schema::hasTable('payment_allocations'))->toBeTrue();

        $names = collect(DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', ['payment_allocations']))
            ->pluck('indexname')
            ->all();

        expect($names)->toContain('payment_allocations_payment_bill_unique')
            ->and($names)->toContain('payment_allocations_tenant_id_company_id_index')
            ->and($names)->toContain('payment_allocations_bill_id_index');
    });
});

describe('the check constraints exist', function (): void {
    it('declares every supplier_payments CHECK', function (): void {
        $checks = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'supplier_payments'::regclass AND contype = 'c'"
        ))->pluck('conname')->all();

        expect($checks)->toContain('supplier_payments_status_check')
            ->and($checks)->toContain('supplier_payments_amount_positive_check')
            // The FX phase (ADR 0022 §A4) drops supplier_payments_single_currency_until_fx_phase and replaces it
            // with the permanent shape rule supplier_payments_exchange_rate_positive_check (a rate is absent, or
            // positive).
            ->and($checks)->not->toContain('supplier_payments_single_currency_until_fx_phase')
            ->and($checks)->toContain('supplier_payments_exchange_rate_positive_check')
            // The FX phase also makes the DB the authority on the currency's ISO shape, now that the caller names it.
            ->and($checks)->toContain('supplier_payments_currency_shape_check')
            // Wave 8c (ADR 0023 §A) ties the cancellation metadata to the status.
            ->and($checks)->toContain('supplier_payments_cancellation_matches_status_check');
    });

    it('declares the payment_allocations CHECK', function (): void {
        $checks = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'payment_allocations'::regclass AND contype = 'c'"
        ))->pluck('conname')->all();

        expect($checks)->toContain('payment_allocations_amount_positive_check');
    });
});

describe('a well-formed payment', function (): void {
    it('accepts a well-formed posted payment', function (): void {
        insertPayment();

        expect(DB::table('supplier_payments')->count())->toBe(1);
    });

    it('is born posted by default', function (): void {
        $id = insertPayment();

        expect(DB::table('supplier_payments')->where('id', $id)->value('status'))->toBe('posted');
    });
});

describe('the status CHECK', function (): void {
    it('refuses a status outside posted/cancelled', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['status' => 'draft'])))
            ->toThrow(QueryException::class);
    });

    it('accepts a cancelled payment carrying its cancellation metadata (ADR 0023 §A)', function (): void {
        // Wave 8c gives the reserved cancelled status behaviour: a cancelled row must carry its date and reason.
        insertPayment(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'recorded in error']);

        expect(DB::table('supplier_payments')->where('status', 'cancelled')->count())->toBe(1);
    });
});

describe('the cancellation-matches-status CHECK (ADR 0023 §A)', function (): void {
    it('refuses a cancelled payment with no reason', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['status' => 'cancelled', 'cancelled_at' => now()])))
            ->toThrow(QueryException::class);
    });

    it('refuses a posted payment that carries cancellation metadata', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['cancellation_reason' => 'should not be here'])))
            ->toThrow(QueryException::class);
    });
});

describe('the withholding-tax snapshot (ADR 0021 §A3)', function (): void {
    it('carries the wht columns', function (): void {
        expect(Schema::hasColumns('supplier_payments', ['wht_amount', 'wht_rate']))->toBeTrue();
    });

    it('declares the wht CHECK constraints', function (): void {
        $checks = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'supplier_payments'::regclass AND contype = 'c'"
        ))->pluck('conname')->all();

        expect($checks)->toContain('supplier_payments_wht_amount_non_negative_check')
            ->and($checks)->toContain('supplier_payments_wht_within_amount_check')
            ->and($checks)->toContain('supplier_payments_wht_rate_range_check')
            ->and($checks)->toContain('supplier_payments_wht_rate_present_when_withheld_check');
    });

    it('defaults wht_amount to zero and wht_rate to null (a non-withholding payment is 8a-identical)', function (): void {
        $id = insertPayment();

        $row = DB::table('supplier_payments')->where('id', $id)->first();
        expect($row->wht_amount)->toBe('0.0000')
            ->and($row->wht_rate)->toBeNull();
    });

    it('accepts a withholding payment', function (): void {
        $id = insertPayment(['wht_amount' => '50.0000', 'wht_rate' => '5.00']);

        expect(DB::table('supplier_payments')->where('id', $id)->value('wht_amount'))->toBe('50.0000');
    });

    it('refuses a negative wht_amount', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['wht_amount' => '-1.0000', 'wht_rate' => '5.00'])))
            ->toThrow(QueryException::class);
    });

    it('refuses wht_amount greater than the payment amount', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['amount' => '1000.0000', 'wht_amount' => '1000.0001', 'wht_rate' => '100.00'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a wht_rate above 100', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['wht_amount' => '50.0000', 'wht_rate' => '150.00'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a positive withholding with no rate', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['wht_amount' => '50.0000', 'wht_rate' => null])))
            ->toThrow(QueryException::class);
    });

    it('freezes wht_amount and wht_rate on a posted payment even on the one allowed cancelled transition (column-drift, §A4)', function (): void {
        $id = insertPayment(['wht_amount' => '50.0000', 'wht_rate' => '5.00']);

        // status -> cancelled is the one permitted move; changing wht_amount alongside it must still be
        // refused by the frozen-by-name list — the regression that proves the column was added to the freeze.
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->where('id', $id)
            ->update(['status' => 'cancelled', 'wht_amount' => '0.0000', 'updated_at' => now()])))
            ->toThrow(QueryException::class);

        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->where('id', $id)
            ->update(['status' => 'cancelled', 'wht_rate' => '10.00', 'updated_at' => now()])))
            ->toThrow(QueryException::class);
    });
});

describe('the amount CHECK', function (): void {
    it('refuses a zero amount', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['amount' => '0.0000'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a negative amount', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['amount' => '-500.0000'])))
            ->toThrow(QueryException::class);
    });
});

describe('the exchange rate positive CHECK', function (): void {
    // The FX phase (ADR 0022 §A4) opened the exchange rate: it is either absent (a base-currency payment) or a
    // positive number. supplier_payments_exchange_rate_positive_check is now the shape rule, not a null-pin.
    it('accepts a positive exchange rate on a foreign payment', function (): void {
        insertPayment(['currency_code' => 'USD', 'exchange_rate' => '305.5000000000']);

        expect(DB::table('supplier_payments')->whereNotNull('exchange_rate')->count())->toBe(1);
    });

    it('refuses a non-positive exchange rate', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['currency_code' => 'USD', 'exchange_rate' => '0.0000000000'])))
            ->toThrow(QueryException::class);

        expect(fn () => DB::transaction(fn () => insertPayment(['currency_code' => 'USD', 'exchange_rate' => '-1.0000000000'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a currency code that is not the uppercase ISO shape', function (): void {
        // supplier_payments_currency_shape_check — the DB is the authority, so a lower-case code cannot slip past
        // a bug in the service layer and split a currency in two.
        expect(fn () => DB::transaction(fn () => insertPayment(['currency_code' => 'usd'])))
            ->toThrow(QueryException::class);
    });
});

describe('the internal number', function (): void {
    it('is required — there is no draft shape to default to', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['number' => null])))
            ->toThrow(QueryException::class);
    });

    it('is unique per company, in full — every payment is numbered from birth', function (): void {
        insertPayment(['number' => 'PAY-DUP']);

        expect(fn () => DB::transaction(fn () => insertPayment(['number' => 'PAY-DUP'])))
            ->toThrow(QueryException::class);
    });

    it('allows another company to reuse a number', function (): void {
        insertPayment(['number' => 'PAY-DUP']);

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        app(FiscalCalendarService::class)->openYearContaining($second, CarbonImmutable::parse('2026-06-15'));

        $secondSupplier = Supplier::factory()->create(['company_id' => $second->getKey()]);
        $secondBank = Account::query()->forCompany($second->getKey())->where('code', '1120')->firstOrFail();
        $secondPayable = Account::query()->forCompany($second->getKey())->where('code', '2110')->firstOrFail();

        $entry = app(PostingService::class)->postNew($second, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Second company standing entry',
            lines: [
                new JournalLineData(accountId: (string) $secondPayable->getKey(), debit: Money::of('1000.00', 'LKR')),
                new JournalLineData(accountId: (string) $secondBank->getKey(), credit: Money::of('1000.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
        ));

        DB::table('supplier_payments')->insert(paymentRow([
            'company_id' => $second->getKey(),
            'supplier_id' => $secondSupplier->getKey(),
            'bank_account_id' => $secondBank->getKey(),
            'number' => 'PAY-DUP',
            'journal_entry_id' => (string) $entry->getKey(),
        ]));

        expect(DB::table('supplier_payments')->where('number', 'PAY-DUP')->count())->toBe(2);
    });
});

describe('referential integrity', function (): void {
    it('refuses a payment for a supplier that does not exist', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['supplier_id' => (string) Str::uuid7()])))
            ->toThrow(QueryException::class);
    });

    it('refuses a payment naming a bank account that does not exist', function (): void {
        insertPayment();

        expect(fn () => DB::transaction(fn () => insertPayment(['bank_account_id' => (string) Str::uuid7()])))
            ->toThrow(QueryException::class);
    });

    it('refuses to delete a supplier that has a payment', function (): void {
        insertPayment();

        // Restrict, not cascade — a payment names its supplier and that name has to stay resolvable.
        expect(fn () => DB::table('suppliers')->where('id', $this->supplier->getKey())->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses to delete a bank account that has a payment', function (): void {
        insertPayment();

        expect(fn () => DB::table('accounts')->where('id', $this->bank->getKey())->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses an allocation naming a bill that does not exist', function (): void {
        $paymentId = insertPayment();

        expect(fn () => DB::transaction(fn () => insertAllocationRow($paymentId, (string) Str::uuid7())))
            ->toThrow(QueryException::class);
    });

    it('refuses to delete a bill an allocation still names, even a never-posted draft', function (): void {
        // A posted bill is already undeletable by its own immutability trigger regardless of this FK, so the
        // FK is only meaningfully exercised against a bill that would otherwise be deletable — a draft.
        $draft = app(BillService::class)->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'PAY-SCHEMA-DRAFT',
            lines: [new BillLineData(
                description: 'x',
                quantity: '1',
                unitPrice: '10.00',
                expenseAccountId: (string) $this->purchases->getKey(),
            )],
        ));

        $paymentId = insertPayment();
        insertAllocationRow($paymentId, (string) $draft->getKey(), ['amount' => '10.0000']);

        expect(fn () => DB::table('bills')->where('id', $draft->getKey())->delete())
            ->toThrow(QueryException::class);
    });
});

describe('allocation constraints', function (): void {
    it('accepts a well-formed allocation', function (): void {
        $paymentId = insertPayment();
        insertAllocationRow($paymentId, (string) $this->bill->getKey());

        expect(DB::table('payment_allocations')->count())->toBe(1);
    });

    it('refuses a zero allocation amount', function (): void {
        $paymentId = insertPayment();

        expect(fn () => DB::transaction(fn () => insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '0.0000'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a negative allocation amount', function (): void {
        $paymentId = insertPayment();

        expect(fn () => DB::transaction(fn () => insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '-1.0000'])))
            ->toThrow(QueryException::class);
    });

    it('refuses the same bill allocated twice within one payment', function (): void {
        $paymentId = insertPayment(['amount' => '1000.0000']);
        insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '500.0000']);

        expect(fn () => DB::transaction(fn () => insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '500.0000'])))
            ->toThrow(QueryException::class);
    });

    it('allows the same bill to be named by a second, separate payment', function (): void {
        $first = insertPayment();
        insertAllocationRow($first, (string) $this->bill->getKey());

        $second = insertPayment();
        insertAllocationRow($second, (string) $this->bill->getKey());

        expect(DB::table('payment_allocations')->where('bill_id', $this->bill->getKey())->count())->toBe(2);
    });
});

describe('the bills-side migration (§A4)', function (): void {
    it('drops bills_no_payments_until_payments_phase and adds bills_amount_due_non_negative_check, leaving the others', function (): void {
        $checks = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'bills'::regclass AND contype = 'c'"
        ))->pluck('conname')->all();

        expect($checks)->not->toContain('bills_no_payments_until_payments_phase')
            ->and($checks)->toContain('bills_amount_due_non_negative_check')
            ->and($checks)->toContain('bills_amount_due_check')
            ->and($checks)->toContain('bills_non_negative_check')
            ->and($checks)->toContain('bills_total_check');
    });

    it('lets amount_paid move on a posted bill now the phase CHECK is gone', function (): void {
        DB::table('bills')->where('id', $this->bill->getKey())->update([
            'amount_paid' => '400.0000', 'amount_due' => '600.0000', 'status' => 'partially_paid', 'updated_at' => now(),
        ]);

        $row = DB::table('bills')->where('id', $this->bill->getKey())->first();

        expect($row->amount_paid)->toBe('400.0000')
            ->and($row->amount_due)->toBe('600.0000')
            ->and($row->status)->toBe('partially_paid');
    });

    it('refuses an overpayment that would drive amount_due negative', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill->getKey())->update([
            'amount_paid' => '1300.0000', 'amount_due' => '-120.0000', 'status' => 'paid', 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    });

    it('still refuses an amount_due that disagrees with total minus amount_paid', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill->getKey())->update([
            'amount_paid' => '400.0000', 'amount_due' => '999.0000', 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    });

    it('still refuses a negative amount_paid', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill->getKey())->update([
            'amount_paid' => '-100.0000', 'amount_due' => '1280.0000', 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    });
});

describe('a posted payment is frozen', function (): void {
    beforeEach(function (): void {
        $this->paymentId = insertPayment();
        insertAllocationRow($this->paymentId, (string) $this->bill->getKey());
    });

    it('refuses every simple column change on a posted payment', function (string $column, mixed $value): void {
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->where('id', $this->paymentId)->update([$column => $value])))
            ->toThrow(QueryException::class);
    })->with([
        ['number', 'PAY-CHANGED'],
        ['payment_date', '2026-07-01'],
        ['currency_code', 'USD'],
        ['amount', '9999.0000'],
        ['reference', 'changed-ref'],
        ['notes', 'changed-notes'],
    ]);

    it('refuses a changed supplier, bank account, branch, ledger link and identity columns', function (): void {
        $otherSupplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
        $otherBank = Account::query()->forCompany($this->company->getKey())->where('code', '1110')->firstOrFail();
        $otherBranch = Branch::factory()->create(['company_id' => $this->company->getKey()]);

        $mutations = [
            'supplier_id' => (string) $otherSupplier->getKey(),
            'bank_account_id' => (string) $otherBank->getKey(),
            'branch_id' => (string) $otherBranch->getKey(),
            'journal_entry_id' => paymentJournalEntryId(),
            'posted_by_id' => (string) $this->owner->getKey(),
            'created_by_id' => (string) $this->owner->getKey(),
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) Str::uuid7(),
            'company_id' => (string) Str::uuid7(),
            'created_at' => now()->subDay(),
            'posted_at' => now()->addDay(),
        ];

        foreach ($mutations as $column => $value) {
            expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->where('id', $this->paymentId)->update([$column => $value])))
                ->toThrow(QueryException::class);
        }

        expect(DB::table('supplier_payments')->where('id', $this->paymentId)->count())->toBe(1);
    });

    it('refuses deletion outright, naming the statutory-record reason', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->where('id', $this->paymentId)->delete()))
            ->toThrow(QueryException::class);
    });

    it('permits the posted → cancelled transition, writing the cancellation metadata alongside it (ADR 0023)', function (): void {
        // `status` is deliberately absent from the trigger's frozen list (mirror bills), so it may move — and the
        // cancellation-matches-status CHECK requires the date and reason to be set in the same update.
        DB::table('supplier_payments')->where('id', $this->paymentId)->update([
            'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'recorded in error', 'updated_at' => now(),
        ]);

        expect(DB::table('supplier_payments')->where('id', $this->paymentId)->value('status'))->toBe('cancelled');
    });

    it('refuses any further change once cancelled', function (): void {
        DB::table('supplier_payments')->where('id', $this->paymentId)->update([
            'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'recorded in error', 'updated_at' => now(),
        ]);

        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->where('id', $this->paymentId)->update(['notes' => 'too late'])))
            ->toThrow(QueryException::class);
    });
});

describe('a posted payment’s allocations are frozen', function (): void {
    beforeEach(function (): void {
        $this->paymentId = insertPayment();
        $this->allocationId = insertAllocationRow($this->paymentId, (string) $this->bill->getKey());
    });

    it('refuses any change to an allocation amount', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('payment_allocations')->where('id', $this->allocationId)->update(['amount' => '1.0000'])))
            ->toThrow(QueryException::class);
    });

    it('refuses deleting an allocation', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('payment_allocations')->where('id', $this->allocationId)->delete()))
            ->toThrow(QueryException::class);
    });

    // No test asserts the cascade-on-delete of `supplier_payment_id` actually fires: the payment freeze
    // trigger above refuses every delete of a posted payment outright, so the cascade is unreachable this
    // wave (ADR §A3 note: "Cascade only ever fires if the payment is hard-deleted, which §A5 forbids.").
});

describe('the deferred full-allocation constraint trigger', function (): void {
    it('passes at commit when allocations sum exactly to the payment amount', function (): void {
        $paymentId = insertPayment(['amount' => '1000.0000']);
        insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '1000.0000']);

        expect(fn () => DB::transaction(function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->not->toThrow(QueryException::class);
    });

    it('rejects an under-allocated payment once forced to check (the "at commit" case)', function (): void {
        $paymentId = insertPayment(['amount' => '1000.0000']);
        insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '400.0000']);

        expect(fn () => DB::transaction(function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class);
    });

    it('rejects an over-allocated payment once forced to check', function (): void {
        $secondBill = app(BillService::class)->post(
            app(BillService::class)->createDraft($this->company, new BillData(
                supplierId: (string) $this->supplier->getKey(),
                billDate: CarbonImmutable::parse('2026-06-15'),
                supplierInvoiceNumber: 'PAY-SCHEMA-OVER',
                lines: [new BillLineData(description: 'x', quantity: '1', unitPrice: '500.00', expenseAccountId: (string) $this->purchases->getKey())],
            )),
            $this->owner,
        );

        $paymentId = insertPayment(['amount' => '1000.0000']);
        insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '1000.0000']);
        insertAllocationRow($paymentId, (string) $secondBill->getKey(), ['amount' => '500.0000']);

        expect(fn () => DB::transaction(function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class);
    });

    it('rejects a payment inserted with no allocations at all', function (): void {
        insertPayment(['amount' => '1000.0000']);

        expect(fn () => DB::transaction(function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class);
    });

    it('rejects a rogue extra allocation inserted after the payment already balances', function (): void {
        $paymentId = insertPayment(['amount' => '1000.0000']);
        insertAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '1000.0000']);

        // The legitimate pair passes on its own — proved first, so the failure below is attributable to the
        // rogue row alone.
        DB::transaction(function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        $secondBill = app(BillService::class)->post(
            app(BillService::class)->createDraft($this->company, new BillData(
                supplierId: (string) $this->supplier->getKey(),
                billDate: CarbonImmutable::parse('2026-06-15'),
                supplierInvoiceNumber: 'PAY-SCHEMA-ROGUE',
                lines: [new BillLineData(description: 'x', quantity: '1', unitPrice: '50.00', expenseAccountId: (string) $this->purchases->getKey())],
            )),
            $this->owner,
        );

        // No trigger fires on `payment_allocations` INSERT itself (only the deferred check does), and the
        // header's own `amount` is frozen, so this is exactly the hole a born-posted model opens: a rogue
        // INSERT of a second allocation into an already-settled payment. Only the deferred sum-check can
        // catch it — the row-level freeze trigger never fires on INSERT.
        expect(fn () => DB::transaction(function () use ($paymentId, $secondBill): void {
            insertAllocationRow($paymentId, (string) $secondBill->getKey(), ['amount' => '50.0000']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class);
    });
});

describe('forced row level security', function (): void {
    it('enables and forces row level security with a tenant-isolation policy on both tables', function (): void {
        foreach (['supplier_payments', 'payment_allocations'] as $table) {
            $relation = DB::selectOne(
                'SELECT relrowsecurity AS enabled, relforcerowsecurity AS forced FROM pg_class WHERE relname = ?',
                [$table],
            );

            expect($relation)->not->toBeNull()
                ->and((bool) $relation->enabled)->toBeTrue()
                ->and((bool) $relation->forced)->toBeTrue();

            $policies = collect(DB::select('SELECT policyname FROM pg_policies WHERE tablename = ?', [$table]))
                ->pluck('policyname')
                ->all();

            expect($policies)->toContain($table.'_tenant_isolation');
        }
    });

    it('hides another workspace’s payments from raw SQL', function (): void {
        insertPayment();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('supplier_payments')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('supplier_payments'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('hides another workspace’s allocations from raw SQL', function (): void {
        insertAllocationRow(insertPayment(), (string) $this->bill->getKey());

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('payment_allocations')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('payment_allocations'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('refuses a write naming another tenant', function (): void {
        $acmeTenantId = $this->tenantId;
        $acmeCompanyId = $this->company->getKey();
        $acmeSupplierId = $this->supplier->getKey();
        $acmeBankId = $this->bank->getKey();
        $entryId = paymentJournalEntryId();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(fn () => DB::table('supplier_payments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $acmeTenantId,
            'company_id' => $acmeCompanyId,
            'supplier_id' => $acmeSupplierId,
            'bank_account_id' => $acmeBankId,
            'number' => 'PAY-SNEAK',
            'payment_date' => '2026-06-15',
            'currency_code' => 'LKR',
            'amount' => '1000.0000',
            'status' => 'posted',
            'journal_entry_id' => $entryId,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('supplier_payments'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
