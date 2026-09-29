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
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QA non-vacuity evidence for the savepoint-wrapped CHECK negatives in `PaymentSchemaTest` — the payable-side
 * mirror of `BillSchemaCheckMutationTest.php` (the `SupplierSchemaCheckMutationTest` lesson, commit 80cc5a9).
 *
 * For each CHECK constraint this drops the constraint and re-runs a savepoint-wrapped insert crafted to
 * violate *only that one constraint*, and shows it now succeeds. If a negative in `PaymentSchemaTest` had
 * thrown for the wrong reason (a missing table, the savepoint machinery itself, an unrelated constraint),
 * dropping the *named* constraint would leave the insert still failing, and this mutation would fail. It
 * passing means each real negative fails for the right reason.
 *
 * The deferred full-allocation trigger is not a CHECK constraint, so it gets the equivalent treatment: the
 * trigger(s) executing `asids_assert_payment_fully_allocated()` are discovered dynamically (by function name,
 * not a guessed trigger name — the ADR names the mechanism, "the same AFTER INSERT trigger on
 * supplier_payments", without committing to what the *second* trigger is called) and dropped, then an
 * otherwise-rejected under-allocated payment is shown to pass `SET CONSTRAINTS ALL IMMEDIATE` once they are
 * gone — proving the earlier "rejects" tests were the trigger's doing, not an accident of test order.
 *
 * The DROP runs inside the RefreshDatabase transaction and is rolled back with it, so no schema change
 * outlives the test — QA verification of the tests themselves, adding no production code.
 *
 * RED expectation before Stage 1 lands: no `supplier_payments`/`payment_allocations` tables and no
 * `asids_assert_payment_fully_allocated` function, so every DROP/lookup statement throws "does not exist" and
 * every test errors.
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

    $this->bill = app(BillService::class)->post(
        app(BillService::class)->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'PAY-MUTATION-1',
            lines: [new BillLineData(
                description: 'Office supplies',
                quantity: '1',
                unitPrice: '1180.00',
                expenseAccountId: (string) $this->purchases->getKey(),
            )],
        )),
        $this->owner,
    );
});

/**
 * A fresh posted journal entry, standing in for the JV a real payment would post. Named distinctly from
 * `PaymentSchemaTest`'s helper of the same purpose so the two global Pest helpers do not collide.
 */
function checkMutationPaymentJournalEntryId(): string
{
    $entry = app(PostingService::class)->postNew(test()->company, new JournalEntryData(
        entryDate: CarbonImmutable::parse('2026-06-15'),
        description: 'Mutation-test standing entry',
        lines: [
            new JournalLineData(accountId: (string) test()->tradePayables->getKey(), debit: Money::of('1000.00', 'LKR')),
            new JournalLineData(accountId: (string) test()->bank->getKey(), credit: Money::of('1000.00', 'LKR')),
        ],
        documentType: DocumentType::JournalVoucher,
    ));

    return (string) $entry->getKey();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkMutationPaymentRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'branch_id' => null,
        'supplier_id' => test()->supplier->getKey(),
        'bank_account_id' => test()->bank->getKey(),
        'number' => 'PAY-MUT-'.Str::random(10),
        'payment_date' => '2026-06-15',
        'currency_code' => 'LKR',
        'exchange_rate' => null,
        'amount' => '1000.0000',
        'status' => 'posted',
        'reference' => null,
        'notes' => null,
        'journal_entry_id' => checkMutationPaymentJournalEntryId(),
        'posted_at' => now(),
        'posted_by_id' => null,
        'created_by_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

/**
 * A well-formed payment, committed within the RefreshDatabase transaction, to hang allocation-constraint
 * mutations off.
 */
function checkMutationPayment(): string
{
    $row = checkMutationPaymentRow();
    DB::table('supplier_payments')->insert($row);

    return (string) $row['id'];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkMutationAllocationRow(string $paymentId, string $billId, array $overrides = []): array
{
    return [
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
}

function paymentCheckConstraintPresent(string $name): bool
{
    return DB::selectOne('SELECT count(*) AS n FROM pg_constraint WHERE conname = ?', [$name])->n > 0;
}

/**
 * Every non-internal trigger executing the deferred full-allocation function, on whichever table(s) it was
 * declared on and under whatever name(s) were chosen — discovered by the function it calls rather than by a
 * guessed trigger name, since the ADR commits only to the mechanism ("the same trigger" on both tables), not
 * to what the second one is called.
 *
 * @return list<object{tgname: string, relname: string}>
 */
function fullAllocationTriggers(): array
{
    /** @var list<object{tgname: string, relname: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT pg_trigger.tgname AS tgname, pg_class.relname AS relname
        FROM pg_trigger
        JOIN pg_class ON pg_class.oid = pg_trigger.tgrelid
        JOIN pg_proc ON pg_proc.oid = pg_trigger.tgfoid
        WHERE pg_proc.proname = 'asids_assert_payment_fully_allocated'
          AND NOT pg_trigger.tgisinternal
    SQL);

    return $rows;
}

function dropFullAllocationTriggers(): void
{
    foreach (fullAllocationTriggers() as $trigger) {
        DB::statement("DROP TRIGGER {$trigger->tgname} ON {$trigger->relname}");
    }
}

describe('the supplier_payments CHECK negatives are non-vacuous', function (): void {
    it('the status CHECK is the only thing rejecting a status outside posted/cancelled', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_status_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_status_check');

        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(checkMutationPaymentRow(['status' => 'draft']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('status', 'draft')->count())->toBe(1);
    });

    it('the amount-positive CHECK is the only thing rejecting a non-positive amount', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_amount_positive_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_amount_positive_check');

        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(checkMutationPaymentRow(['amount' => '0.0000']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('amount', '0.0000')->count())->toBe(1);
    });

    it('the exchange-rate-positive CHECK is the only thing rejecting a non-positive rate', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_exchange_rate_positive_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_exchange_rate_positive_check');

        // A zero rate is otherwise well-formed — only the exchange-rate-positive CHECK could have objected.
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(checkMutationPaymentRow(['currency_code' => 'USD', 'exchange_rate' => '0.0000000000']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('exchange_rate', '0.0000000000')->count())->toBe(1);
    });

    it('the currency-shape CHECK is the only thing rejecting a lower-case currency code', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_currency_shape_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_currency_shape_check');

        // "usd" is otherwise well-formed (three chars, fits char(3)) — only the currency-shape CHECK objects.
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(checkMutationPaymentRow(['currency_code' => 'usd']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('currency_code', 'usd')->count())->toBe(1);
    });

    it('the cancellation-matches-status CHECK is the only thing rejecting a cancelled row with no reason', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_cancellation_matches_status_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_cancellation_matches_status_check');

        // A cancelled row with no cancelled_at/reason is otherwise well-formed — only the cancellation-matches
        // CHECK could object (the status CHECK still admits 'cancelled').
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(checkMutationPaymentRow(['status' => 'cancelled']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('status', 'cancelled')->count())->toBe(1);
    });

    it('the wht-within-amount CHECK is the only thing rejecting wht_amount > amount (ADR 0021 §A3)', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_wht_within_amount_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_within_amount_check');

        // wht_amount 1000.0001 > amount 1000; wht_rate present (100) and >= 0 so the other WHT checks pass —
        // only the within-amount CHECK could have objected.
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(
            checkMutationPaymentRow(['amount' => '1000.0000', 'wht_amount' => '1000.0001', 'wht_rate' => '100.00'])
        )))->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('wht_amount', '1000.0001')->count())->toBe(1);
    });

    it('the wht rate-present-when-withheld CHECK is the only thing rejecting a positive withholding with no rate', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_wht_rate_present_when_withheld_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_rate_present_when_withheld_check');

        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(
            checkMutationPaymentRow(['wht_amount' => '50.0000', 'wht_rate' => null])
        )))->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('wht_amount', '50.0000')->count())->toBe(1);
    });

    it('the wht-amount-non-negative CHECK is the only thing rejecting a negative wht_amount', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_wht_amount_non_negative_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_amount_non_negative_check');

        // wht_amount -1 with a rate present (5.00): within-amount (-1 <= amount) and rate-present both pass, so
        // only the non-negative CHECK could have objected.
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(
            checkMutationPaymentRow(['wht_amount' => '-1.0000', 'wht_rate' => '5.00'])
        )))->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('wht_amount', '-1.0000')->count())->toBe(1);
    });

    it('the wht rate-range CHECK is the only thing rejecting a wht_rate above 100', function (): void {
        expect(paymentCheckConstraintPresent('supplier_payments_wht_rate_range_check'))->toBeTrue();

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_rate_range_check');

        // wht_amount 50 (>= 0, <= amount) with rate 150: non-negative, within-amount and rate-present all pass,
        // so only the range CHECK could have objected.
        expect(fn () => DB::transaction(fn () => DB::table('supplier_payments')->insert(
            checkMutationPaymentRow(['wht_amount' => '50.0000', 'wht_rate' => '150.00'])
        )))->not->toThrow(QueryException::class);

        expect(DB::table('supplier_payments')->where('wht_rate', '150.00')->count())->toBe(1);
    });
});

describe('the payment_allocations CHECK negative is non-vacuous', function (): void {
    it('the amount-positive CHECK is the only thing rejecting a non-positive allocation', function (): void {
        $paymentId = checkMutationPayment();

        expect(paymentCheckConstraintPresent('payment_allocations_amount_positive_check'))->toBeTrue();

        DB::statement('ALTER TABLE payment_allocations DROP CONSTRAINT payment_allocations_amount_positive_check');

        expect(fn () => DB::transaction(fn () => DB::table('payment_allocations')->insert(
            checkMutationAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '0.0000'])
        )))->not->toThrow(QueryException::class);

        expect(DB::table('payment_allocations')->where('amount', '0.0000')->count())->toBe(1);
    });
});

describe('the bills-side CHECK negative is non-vacuous', function (): void {
    it('bills_amount_due_non_negative_check is the only thing rejecting an overpayment, independent of the surviving checks', function (): void {
        expect(paymentCheckConstraintPresent('bills_amount_due_non_negative_check'))->toBeTrue();

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_amount_due_non_negative_check');

        // amount_due (-120) = total (1180) - amount_paid (1300): bills_amount_due_check is satisfied, and
        // amount_paid (1300) >= 0 satisfies bills_non_negative_check too — isolating the dropped CHECK as the
        // only constraint that could have rejected this row.
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill->getKey())->update([
            'amount_paid' => '1300.0000', 'amount_due' => '-120.0000', 'status' => 'paid', 'updated_at' => now(),
        ])))->not->toThrow(QueryException::class);

        expect(DB::table('bills')->where('id', $this->bill->getKey())->value('amount_due'))->toBe('-120.0000');
    });
});

describe('the deferred full-allocation trigger is non-vacuous', function (): void {
    it('dropping every trigger backed by asids_assert_payment_fully_allocated lets an under-allocated payment pass', function (): void {
        $triggers = fullAllocationTriggers();

        // Both the `payment_allocations`-side trigger and its `supplier_payments`-side companion (the "AFTER
        // INSERT ... so a payment inserted with no allocations at all is caught at commit" mechanism, ADR
        // §A5(3)) must exist before this proves anything.
        expect($triggers)->not->toBeEmpty();

        dropFullAllocationTriggers();

        $paymentId = checkMutationPayment();
        DB::table('payment_allocations')->insert(checkMutationAllocationRow($paymentId, (string) $this->bill->getKey(), ['amount' => '400.0000']));

        // Under-allocated (400 against an amount of 1000) and — with the trigger(s) gone — no longer refused,
        // even once every deferred constraint is forced to check.
        expect(fn () => DB::transaction(function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->not->toThrow(QueryException::class);
    });
});
