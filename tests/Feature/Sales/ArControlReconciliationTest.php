<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceivableReportService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether the sales ledger and the general ledger agree about receivables.
 *
 * Phase 4C. The subledger says what the invoices claim is owed; the general ledger says what the receivable
 * accounts hold. A difference is something that reached an AR account without going through an invoice, and
 * surfacing that is the entire point.
 *
 * TWO TESTS CARRY THIS FILE
 * -------------------------
 * **The repointed customer.** `customer.receivable_account_id` is mutable. Grouping the subledger by the
 * customer's *current* setting would move an old invoice's balance to the new account while the ledger kept it
 * in the old one — two equal and opposite differences that cancel in the total. The report would invent a
 * discrepancy rather than find one. That test proves the grouping follows the posting instead.
 *
 * **The posting order.** The receivable account is identified as line number 1 of the invoice's journal entry,
 * which is provable from `InvoicePostingMap` today but couples this report to the map's ordering. The ordering
 * is asserted directly, so the map cannot be reordered without failing a test here first — rather than
 * silently misattributing every balance in production.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::now());

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();
    $this->tradeReceivables = Account::query()->forCompany($this->company->getKey())
        ->withSystemKey(Account::TRADE_RECEIVABLES)->firstOrFail();
    $this->otherReceivables = Account::query()->forCompany($this->company->getKey())
        ->where('code', '1140')->firstOrFail();

    $this->silva = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->reports = app(ReceivableReportService::class);
});

/**
 * A customer, optionally with its own receivable account. Named distinctly — Pest helpers are global.
 */
function reconCustomer(string $code, ?Account $receivable = null, ?Company $company = null): Customer
{
    return app(CustomerService::class)->create(
        $company ?? test()->company,
        new CustomerData(
            name: $code.' Ltd',
            code: $code,
            receivableAccountId: $receivable === null ? null : (string) $receivable->getKey(),
        ),
    );
}

/**
 * An issued invoice, dated today so its posting lands in the open period.
 */
function reconInvoice(Customer $customer, string $unitPrice, ?Company $company = null, ?Account $revenue = null): SalesInvoice
{
    $company ??= test()->company;
    $today = CarbonImmutable::now()->startOfDay();

    $draft = app(SalesInvoiceService::class)->createDraft($company, new SalesInvoiceData(
        customerId: (string) $customer->getKey(),
        invoiceDate: $today,
        dueDate: $today,
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) ($revenue ?? test()->revenue)->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

/**
 * The reconciliation keyed by account code, for readable assertions.
 *
 * @return array<string, array{subledger: string, general_ledger: string, difference: string, reconciles: bool}>
 */
function reconByCode(array $report): array
{
    $out = [];

    foreach ($report['rows'] as $row) {
        $out[(string) $row['account']->code] = [
            'subledger' => $row['subledger']->toDecimalString(),
            'general_ledger' => $row['general_ledger']->toDecimalString(),
            'difference' => $row['difference']->toDecimalString(),
            'reconciles' => $row['reconciles'],
        ];
    }

    return $out;
}

/**
 * Posts a manual journal touching an account directly, bypassing invoicing.
 */
function reconManualJournal(Account $debit, Account $credit, string $amount): void
{
    $today = CarbonImmutable::now()->startOfDay();

    app(PostingService::class)->postNew(test()->company, new JournalEntryData(
        entryDate: $today,
        description: 'Manual journal posted outside invoicing',
        lines: [
            new JournalLineData(accountId: (string) $debit->getKey(), debit: Money::of($amount, 'LKR')),
            new JournalLineData(accountId: (string) $credit->getKey(), credit: Money::of($amount, 'LKR')),
        ],
    ), test()->owner);
}

describe('the account mapping this report depends on', function (): void {
    it('puts the receivable line first in every invoice posting', function (): void {
        // The structural property the whole report rests on. Asserted directly so the posting map cannot be
        // reordered without failing here rather than silently misattributing balances in production.
        $invoice = reconInvoice($this->silva, '1000.00');

        $firstLine = DB::table('journal_lines')
            ->where('journal_entry_id', $invoice->journal_entry_id)
            ->where('line_number', 1)
            ->first();

        expect($firstLine->account_id)->toBe((string) $this->tradeReceivables->getKey())
            ->and($firstLine->debit)->toBe('1000.0000')
            ->and($firstLine->credit)->toBe('0.0000');
    });

});

describe('a reconciled company', function (): void {
    it('reports nothing when no invoice has ever posted', function (): void {
        $report = $this->reports->arControlReconciliation($this->company);

        // The system account still appears — it exists and holds nothing, which is itself the reconciled state.
        expect($report['rows'])->toHaveCount(1)
            ->and(reconByCode($report)[$this->tradeReceivables->code])->toBe([
                'subledger' => '0.0000',
                'general_ledger' => '0.0000',
                'difference' => '0.0000',
                'reconciles' => true,
            ])
            ->and($report['totals']['reconciles'])->toBeTrue();
    });

    it('reconciles a single issued invoice', function (): void {
        reconInvoice($this->silva, '1000.00');

        $report = $this->reports->arControlReconciliation($this->company);
        $row = reconByCode($report)[$this->tradeReceivables->code];

        expect($row['subledger'])->toBe('1000.0000')
            ->and($row['general_ledger'])->toBe('1000.0000')
            ->and($row['difference'])->toBe('0.0000')
            ->and($row['reconciles'])->toBeTrue()
            ->and($report['totals']['reconciles'])->toBeTrue();
    });

    it('reconciles several invoices', function (): void {
        reconInvoice($this->silva, '1000.00');
        reconInvoice($this->silva, '250.50');
        reconInvoice(reconCustomer('PERERA'), '99.49');

        $report = $this->reports->arControlReconciliation($this->company);

        expect($report['totals']['subledger']->toDecimalString())->toBe('1349.9900')
            ->and($report['totals']['general_ledger']->toDecimalString())->toBe('1349.9900')
            ->and($report['totals']['difference']->toDecimalString())->toBe('0.0000')
            ->and($report['totals']['reconciles'])->toBeTrue();
    });

    it('still reconciles after a cancellation', function (): void {
        $kept = reconInvoice($this->silva, '1000.00');
        $cancelled = reconInvoice($this->silva, '4000.00');

        app(SalesInvoiceService::class)->cancel($cancelled, 'Ordered in error', $this->owner);

        // Both sides reach the same figure by different routes: the subledger drops the invoice by status, and
        // the ledger nets the original against its reversal. Neither is zeroed by hand.
        $report = $this->reports->arControlReconciliation($this->company);

        expect($report['totals']['subledger']->toDecimalString())->toBe('1000.0000')
            ->and($report['totals']['general_ledger']->toDecimalString())->toBe('1000.0000')
            ->and($report['totals']['reconciles'])->toBeTrue()
            ->and($kept->refresh()->status)->toBe(SalesInvoiceStatus::Issued);
    });

    it('reconciles a partially paid invoice on its remaining balance', function (): void {
        $invoice = reconInvoice($this->silva, '1000.00');

        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::PartiallyPaid->value,
            'amount_paid' => '400.0000',
            'amount_due' => '600.0000',
        ]);

        // The subledger now says 600 while the ledger still says 1,000, because no receipt has been posted —
        // Phase 4's job. The 400 difference is real and the report says so rather than hiding it.
        $report = $this->reports->arControlReconciliation($this->company);
        $row = reconByCode($report)[$this->tradeReceivables->code];

        expect($row['subledger'])->toBe('600.0000')
            ->and($row['general_ledger'])->toBe('1000.0000')
            ->and($row['difference'])->toBe('400.0000')
            ->and($row['reconciles'])->toBeFalse();
    });
});

describe('multiple receivable accounts', function (): void {
    it('reconciles each account independently', function (): void {
        $withOwnAccount = reconCustomer('OWNACC', $this->otherReceivables);

        reconInvoice($this->silva, '1000.00');
        reconInvoice($withOwnAccount, '2500.00');

        $byCode = reconByCode($this->reports->arControlReconciliation($this->company));

        expect($byCode[$this->tradeReceivables->code]['subledger'])->toBe('1000.0000')
            ->and($byCode[$this->tradeReceivables->code]['general_ledger'])->toBe('1000.0000')
            ->and($byCode[$this->otherReceivables->code]['subledger'])->toBe('2500.0000')
            ->and($byCode[$this->otherReceivables->code]['general_ledger'])->toBe('2500.0000')
            ->and($byCode[$this->otherReceivables->code]['reconciles'])->toBeTrue();
    });

    it('groups by the account the invoice actually posted to, not the customer’s current setting', function (): void {
        // The test the whole design turns on.
        $customer = reconCustomer('MOVED');

        // Issued while the customer had no override, so it posted to the system account.
        reconInvoice($customer, '1000.00');

        // Repointed afterwards. `receivable_account_id` is mutable and nothing about the posted invoice changes.
        app(CustomerService::class)->update($customer->fresh(), [
            'receivable_account_id' => (string) $this->otherReceivables->getKey(),
        ]);

        // A second invoice, now posting to the new account.
        reconInvoice($customer->fresh(), '400.00');

        $byCode = reconByCode($this->reports->arControlReconciliation($this->company));

        // Grouping by the customer's current setting would put all 1,400 against 1140, leaving the system
        // account showing −1,000 and 1140 showing +1,000 — two invented differences that cancel in the total.
        expect($byCode[$this->tradeReceivables->code]['subledger'])->toBe('1000.0000')
            ->and($byCode[$this->tradeReceivables->code]['difference'])->toBe('0.0000')
            ->and($byCode[$this->otherReceivables->code]['subledger'])->toBe('400.0000')
            ->and($byCode[$this->otherReceivables->code]['difference'])->toBe('0.0000')
            ->and($this->reports->arControlReconciliation($this->company)['totals']['reconciles'])->toBeTrue();
    });

    it('still lists an account whose invoices have all been settled', function (): void {
        $customer = reconCustomer('MOVED2', $this->otherReceivables);
        $invoice = reconInvoice($customer, '900.00');

        // Repointed away, and the old invoice cancelled — so nothing collectable remains against 1140.
        app(SalesInvoiceService::class)->cancel($invoice, 'Ordered in error', $this->owner);

        $byCode = reconByCode($this->reports->arControlReconciliation($this->company));

        // The account still appears with a zero balance. Dropping it is how a stranded ledger balance on an
        // abandoned AR account would go unnoticed.
        expect($byCode)->toHaveKey($this->otherReceivables->code)
            ->and($byCode[$this->otherReceivables->code]['subledger'])->toBe('0.0000')
            ->and($byCode[$this->otherReceivables->code]['general_ledger'])->toBe('0.0000');
    });
});

describe('activity that did not come from an invoice', function (): void {
    it('reports a manual debit to the receivable account as a difference', function (): void {
        reconInvoice($this->silva, '1000.00');

        // Somebody journals straight into AR. This is the thing the report exists to catch.
        reconManualJournal($this->tradeReceivables, $this->revenue, '150.00');

        $row = reconByCode($this->reports->arControlReconciliation($this->company))[$this->tradeReceivables->code];

        expect($row['subledger'])->toBe('1000.0000')
            ->and($row['general_ledger'])->toBe('1150.0000')
            // Positive: the ledger carries more receivable than the invoices account for.
            ->and($row['difference'])->toBe('150.0000')
            ->and($row['reconciles'])->toBeFalse();
    });

    it('reports a manual credit as a negative difference', function (): void {
        reconInvoice($this->silva, '1000.00');

        reconManualJournal($this->revenue, $this->tradeReceivables, '250.00');

        $row = reconByCode($this->reports->arControlReconciliation($this->company))[$this->tradeReceivables->code];

        // Negative, and left visible rather than normalised: the ledger holds less than the invoices claim.
        expect($row['general_ledger'])->toBe('750.0000')
            ->and($row['difference'])->toBe('-250.0000')
            ->and($row['reconciles'])->toBeFalse();
    });

    it('does not turn non-receivable activity into an AR balance', function (): void {
        reconInvoice($this->silva, '1000.00');

        $cash = Account::query()->forCompany($this->company->getKey())->where('code', '1110')->firstOrFail();
        $bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();

        // Two asset accounts, nothing to do with receivables. Neither should appear.
        reconManualJournal($cash, $bank, '5000.00');

        $byCode = reconByCode($this->reports->arControlReconciliation($this->company));

        expect($byCode)->not->toHaveKey($cash->code)
            ->and($byCode)->not->toHaveKey($bank->code)
            ->and($byCode[$this->tradeReceivables->code]['reconciles'])->toBeTrue();
    });

    it('marks the totals unreconciled when any single account disagrees', function (): void {
        $withOwnAccount = reconCustomer('OWNACC', $this->otherReceivables);

        reconInvoice($this->silva, '1000.00');
        reconInvoice($withOwnAccount, '1000.00');

        // Opposite manual entries of equal size: the grand difference is zero while both accounts are wrong.
        reconManualJournal($this->tradeReceivables, $this->revenue, '300.00');
        reconManualJournal($this->revenue, $this->otherReceivables, '300.00');

        $report = $this->reports->arControlReconciliation($this->company);

        expect($report['totals']['difference']->toDecimalString())->toBe('0.0000')
            // A total-only check would call this reconciled. Every account has to agree.
            ->and($report['totals']['reconciles'])->toBeFalse();
    });
});

describe('shape, ordering and precision', function (): void {
    it('returns Money everywhere and the date it was produced', function (): void {
        reconInvoice($this->silva, '1000.00');

        $report = $this->reports->arControlReconciliation($this->company);
        $row = $report['rows'][0];

        expect($row['subledger'])->toBeInstanceOf(Money::class)
            ->and($row['general_ledger'])->toBeInstanceOf(Money::class)
            ->and($row['difference'])->toBeInstanceOf(Money::class)
            ->and($report['totals']['difference'])->toBeInstanceOf(Money::class)
            ->and($report['as_of']->toDateString())->toBe(CarbonImmutable::now()->toDateString())
            ->and($row['subledger']->toDecimalString())->toBe('1000.0000');
    });

    it('sums the totals from the rows', function (): void {
        reconInvoice($this->silva, '1000.00');
        reconInvoice(reconCustomer('OWNACC', $this->otherReceivables), '2500.00');

        $report = $this->reports->arControlReconciliation($this->company);

        foreach (['subledger', 'general_ledger', 'difference'] as $column) {
            $fromRows = array_reduce(
                $report['rows'],
                static fn (Money $carry, array $row): Money => $carry->plus($row[$column]),
                Money::zero('LKR'),
            );

            expect($report['totals'][$column]->toDecimalString())->toBe($fromRows->toDecimalString());
        }
    });

    it('orders rows by account code', function (): void {
        reconInvoice(reconCustomer('OWNACC', $this->otherReceivables), '2500.00');
        reconInvoice($this->silva, '1000.00');

        $codes = array_map(
            static fn (array $r): string => (string) $r['account']->code,
            $this->reports->arControlReconciliation($this->company)['rows'],
        );

        // 1130 before 1140, however the invoices were created — how an accountant reads a chart.
        expect($codes)->toBe(['1130', '1140']);
    });

    it('agrees with the outstanding balance report in total', function (): void {
        reconInvoice($this->silva, '1000.00');
        reconInvoice(reconCustomer('OWNACC', $this->otherReceivables), '2500.00');
        reconInvoice(reconCustomer('PERERA'), '99.49');

        $reconciliation = $this->reports->arControlReconciliation($this->company);

        $balance = array_reduce(
            $this->reports->outstandingBalance($this->company),
            static fn (Money $carry, array $row): Money => $carry->plus($row['outstanding']),
            Money::zero('LKR'),
        );

        // Same invoices grouped two different ways — by customer and by posted account — so the subledger
        // totals must match.
        expect($reconciliation['totals']['subledger']->toDecimalString())
            ->toBe($balance->toDecimalString())
            ->and($balance->toDecimalString())->toBe('3599.4900');
    });
});

describe('company and tenant isolation', function (): void {
    it('does not mix a sibling company’s receivables in', function (): void {
        $sibling = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Acme Exports', code: 'EXPORTS'),
            $this->owner,
        );

        app(ChartTemplateService::class)->apply($sibling);
        app(FiscalCalendarService::class)->openYearContaining($sibling, CarbonImmutable::now());

        $siblingRevenue = Account::query()->forCompany($sibling->getKey())->where('code', '4100')->firstOrFail();
        $siblingCustomer = reconCustomer('SILVA', null, $sibling);

        reconInvoice($siblingCustomer, '7500.00', $sibling, $siblingRevenue);
        reconInvoice($this->silva, '1000.00');

        $acme = $this->reports->arControlReconciliation($this->company);
        $exports = $this->reports->arControlReconciliation($sibling);

        // Both companies have their own 1130 with the same code, so this only passes if every query is
        // company-scoped — including the ledger side.
        expect($acme['totals']['subledger']->toDecimalString())->toBe('1000.0000')
            ->and($acme['totals']['general_ledger']->toDecimalString())->toBe('1000.0000')
            ->and($acme['totals']['reconciles'])->toBeTrue()
            ->and($exports['totals']['subledger']->toDecimalString())->toBe('7500.0000')
            ->and($exports['totals']['general_ledger']->toDecimalString())->toBe('7500.0000')
            ->and($exports['totals']['reconciles'])->toBeTrue();
    });

    it('cannot see another tenant’s receivables', function (): void {
        reconInvoice($this->silva, '1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(SalesInvoice::query()->count())->toBe(0);

        $this->withinTenant($this->acme['tenant']);

        expect($this->reports->arControlReconciliation($this->company)['totals']['subledger']->toDecimalString())
            ->toBe('1000.0000');
    });
});
