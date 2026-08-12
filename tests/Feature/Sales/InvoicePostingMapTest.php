<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\InvoicePostingMap;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBePosted;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The posting map: an invoice turned into journal lines, and nothing else.
 *
 * Stage 2 of Milestone 5. The map writes nothing and posts nothing, which is the point — the accounting shape of a
 * sales invoice can be got wrong here at no cost, and proved right before Stage 3 connects it to the ledger.
 *
 * Two properties matter more than the rest, and every test in the balance group exists to pin them down. The entry
 * must balance *exactly*, not nearly: the deferred constraint trigger on `journal_lines` refuses anything else, so
 * a rounding error surfaces as an invoice that cannot be issued. And no amount may be dropped or duplicated by the
 * grouping: an invoice whose credits sum to less than its debit is not a rounding problem, it is revenue that has
 * vanished.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->sales = mapAccount('4100');
    $this->services = mapAccount('4200');
    $this->otherIncome = mapAccount('4900');
    $this->outputVat = mapAccount('2140');
    $this->payeePayable = mapAccount('2150');
    $this->tradeReceivables = mapAccount('1130');
    $this->bank = mapAccount('1120');

    $this->customer = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->map = app(InvoicePostingMap::class);
});

function mapAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * A tax code charging the given rate into the given output account.
 */
function mapTaxCode(string $code, string $rate, ?string $outputAccountId = null): TaxCode
{
    return app(TaxCodeService::class)->create(test()->company, new TaxCodeData(
        code: $code,
        name: $code.' tax',
        taxType: TaxType::Vat,
        rate: $rate,
        effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        outputAccountId: $outputAccountId ?? (string) test()->outputVat->getKey(),
    ));
}

/**
 * A draft invoice from the given line specs.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3?: string|null}>  $specs  [quantity, unitPrice, revenueAccountId, taxCode]
 */
function mapDraft(array $specs, ?string $headerDiscount = null, ?string $customerId = null): SalesInvoice
{
    $lines = array_map(
        static fn (array $spec, int $index): SalesInvoiceLineData => new SalesInvoiceLineData(
            description: 'Line '.($index + 1),
            quantity: $spec[0],
            unitPrice: $spec[1],
            revenueAccountId: $spec[2],
            taxCode: $spec[3] ?? null,
        ),
        $specs,
        array_keys($specs),
    );

    return app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: $lines,
        discountAmount: $headerDiscount,
    ));
}

/**
 * @param  list<JournalLineData>  $lines
 */
function debitTotal(array $lines, string $currency = 'LKR'): Money
{
    return array_reduce(
        $lines,
        static fn (Money $carry, JournalLineData $line): Money => $line->debit === null ? $carry : $carry->plus($line->debit),
        Money::zero($currency),
    );
}

/**
 * @param  list<JournalLineData>  $lines
 */
function creditTotal(array $lines, string $currency = 'LKR'): Money
{
    return array_reduce(
        $lines,
        static fn (Money $carry, JournalLineData $line): Money => $line->credit === null ? $carry : $carry->plus($line->credit),
        Money::zero($currency),
    );
}

/**
 * @param  list<JournalLineData>  $lines
 * @return list<JournalLineData>
 */
function forAccount(array $lines, Account $account): array
{
    return array_values(array_filter(
        $lines,
        static fn (JournalLineData $line): bool => $line->accountId === (string) $account->getKey(),
    ));
}

describe('the simplest invoice', function (): void {
    it('produces one debit and one credit', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        $lines = $this->map->for($invoice);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->accountId)->toBe((string) $this->tradeReceivables->getKey())
            ->and($lines[0]->debit?->toDecimalString())->toBe('1000.0000')
            ->and($lines[1]->accountId)->toBe((string) $this->sales->getKey())
            ->and($lines[1]->credit?->toDecimalString())->toBe('1000.0000');
    });

    it('debits receivables for the invoice total, not the subtotal', function (): void {
        mapTaxCode('VAT', '18');
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey(), 'VAT']]);

        $lines = $this->map->for($invoice);

        // The customer owes the tax as well as the goods. Debiting the subtotal would understate the receivable by
        // exactly the tax and leave the entry unbalanced.
        expect($lines[0]->debit?->toDecimalString())->toBe('1180.0000')
            ->and($invoice->total)->toBe('1180.0000');
    });

    it('names the customer on every line', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        foreach ($this->map->for($invoice) as $line) {
            expect($line->description)->toContain('Silva Traders');
        }
    });
});

describe('grouping revenue', function (): void {
    it('collapses two lines sharing one revenue account into a single credit', function (): void {
        $invoice = mapDraft([
            ['1', '600.00', (string) $this->sales->getKey()],
            ['1', '400.00', (string) $this->sales->getKey()],
        ]);

        $lines = $this->map->for($invoice);
        $revenue = forAccount($lines, $this->sales);

        // A forty-line invoice against one account should not produce forty ledger lines; the detail lives on the
        // invoice, which the entry cites through its source document.
        expect($revenue)->toHaveCount(1)
            ->and($revenue[0]->credit?->toDecimalString())->toBe('1000.0000')
            ->and($lines)->toHaveCount(2);
    });

    it('keeps distinct revenue accounts as separate credits', function (): void {
        $invoice = mapDraft([
            ['1', '600.00', (string) $this->sales->getKey()],
            ['1', '400.00', (string) $this->services->getKey()],
        ]);

        $lines = $this->map->for($invoice);

        expect($lines)->toHaveCount(3)
            ->and(forAccount($lines, $this->sales)[0]->credit?->toDecimalString())->toBe('600.0000')
            ->and(forAccount($lines, $this->services)[0]->credit?->toDecimalString())->toBe('400.0000');
    });

    it('collapses many lines across several accounts correctly', function (): void {
        $invoice = mapDraft([
            ['1', '100.00', (string) $this->sales->getKey()],
            ['1', '200.00', (string) $this->services->getKey()],
            ['1', '300.00', (string) $this->sales->getKey()],
            ['1', '400.00', (string) $this->otherIncome->getKey()],
            ['1', '500.00', (string) $this->services->getKey()],
        ]);

        $lines = $this->map->for($invoice);

        // Five lines, three accounts, one receivable — four journal lines, and each account's total is the sum of
        // exactly its own lines.
        expect($lines)->toHaveCount(4)
            ->and(forAccount($lines, $this->sales)[0]->credit?->toDecimalString())->toBe('400.0000')
            ->and(forAccount($lines, $this->services)[0]->credit?->toDecimalString())->toBe('700.0000')
            ->and(forAccount($lines, $this->otherIncome)[0]->credit?->toDecimalString())->toBe('400.0000');
    });

    it('orders lines deterministically by account code', function (): void {
        $invoice = mapDraft([
            ['1', '100.00', (string) $this->otherIncome->getKey()],
            ['1', '100.00', (string) $this->sales->getKey()],
        ]);

        $codes = array_map(
            fn (JournalLineData $line): string => Account::query()->whereKey($line->accountId)->value('code'),
            $this->map->for($invoice),
        );

        // Receivable first, then revenue ascending by code — so a printed entry reads the way one would be written,
        // and two runs over the same invoice produce identical output.
        expect($codes)->toBe(['1130', '4100', '4900']);
    });
});

describe('grouping tax', function (): void {
    it('collapses lines sharing one output account into a single tax credit', function (): void {
        mapTaxCode('VAT', '18');
        mapTaxCode('VAT2', '18');

        $invoice = mapDraft([
            ['1', '1000.00', (string) $this->sales->getKey(), 'VAT'],
            ['1', '1000.00', (string) $this->services->getKey(), 'VAT2'],
        ]);

        $lines = $this->map->for($invoice);
        $tax = forAccount($lines, $this->outputVat);

        // Grouped by the account, not by the code: two codes pointing at one liability produce one line, which is
        // what a balance sheet wants.
        expect($tax)->toHaveCount(1)
            ->and($tax[0]->credit?->toDecimalString())->toBe('360.0000');
    });

    it('keeps distinct output accounts as separate tax credits', function (): void {
        mapTaxCode('VAT', '18');
        mapTaxCode('LEVY', '2', (string) $this->payeePayable->getKey());

        $invoice = mapDraft([
            ['1', '1000.00', (string) $this->sales->getKey(), 'VAT'],
            ['1', '1000.00', (string) $this->sales->getKey(), 'LEVY'],
        ]);

        $lines = $this->map->for($invoice);

        expect(forAccount($lines, $this->outputVat)[0]->credit?->toDecimalString())->toBe('180.0000')
            ->and(forAccount($lines, $this->payeePayable)[0]->credit?->toDecimalString())->toBe('20.0000');
    });

    it('handles mixed rates by summing what each line charged', function (): void {
        mapTaxCode('VAT', '18');
        mapTaxCode('LOW', '8');

        $invoice = mapDraft([
            ['1', '1000.00', (string) $this->sales->getKey(), 'VAT'],
            ['1', '1000.00', (string) $this->sales->getKey(), 'LOW'],
        ]);

        $lines = $this->map->for($invoice);

        // No rate is applied here — each line already carries what it charged, so mixed rates need no special
        // handling and cannot drift from what the invoice showed.
        expect(forAccount($lines, $this->outputVat)[0]->credit?->toDecimalString())->toBe('260.0000');
    });

    it('produces no tax line for a zero-rated invoice', function (): void {
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey(), 'ZERO']]);

        $lines = $this->map->for($invoice);

        // Zero-rated is reportable on a return but posts no liability. A zero-amount line could not be stored
        // anyway — `journal_lines_one_sided_check` requires one side to be positive.
        expect($lines)->toHaveCount(2)
            ->and(forAccount($lines, $this->outputVat))->toBeEmpty();
    });

    it('produces no tax line for an exempt invoice', function (): void {
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'EXEMPT',
            name: 'Exempt supply',
            taxType: TaxType::Exempt,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey(), 'EXEMPT']]);

        expect(forAccount($this->map->for($invoice), $this->outputVat))->toBeEmpty();
    });

    it('produces no tax line for a line with no tax code at all', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        expect($this->map->for($invoice))->toHaveCount(2);
    });

    it('taxes only the lines that carry a code on a mixed invoice', function (): void {
        mapTaxCode('VAT', '18');

        $invoice = mapDraft([
            ['1', '1000.00', (string) $this->sales->getKey(), 'VAT'],
            ['1', '1000.00', (string) $this->services->getKey()],
        ]);

        $lines = $this->map->for($invoice);

        expect(forAccount($lines, $this->outputVat)[0]->credit?->toDecimalString())->toBe('180.0000')
            ->and($lines[0]->debit?->toDecimalString())->toBe('2180.0000');
    });
});

describe('the receivable account', function (): void {
    it('falls back to the company system account', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        // Resolved by system key, never by code — a company may renumber its chart freely.
        expect($this->map->receivableAccountFor($invoice)->getKey())->toBe($this->tradeReceivables->getKey())
            ->and($this->map->receivableAccountFor($invoice)->system_key)->toBe(Account::TRADE_RECEIVABLES);
    });

    it('prefers the customer’s own account when it has one', function (): void {
        $segmented = app(CustomerService::class)->create($this->company, new CustomerData(
            name: 'Segmented Ltd',
            code: 'SEG',
            receivableAccountId: (string) mapAccount('1140')->getKey(),
        ));

        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]], customerId: (string) $segmented->getKey());

        expect($this->map->receivableAccountFor($invoice)->code)->toBe('1140');
    });

    it('refuses when the resolved account has been made non-postable', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        // An account archived between drafting and issuing. Draft-time validation cannot catch this, which is why
        // the map re-checks rather than trusting a week-old decision.
        DB::table('accounts')->where('id', $this->tradeReceivables->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        expect(fn () => $this->map->for($invoice->fresh()))
            ->toThrow(InvoiceCannotBePosted::class);
    });

    it('refuses when no receivable account exists at all', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        DB::table('accounts')->where('id', $this->tradeReceivables->getKey())
            ->update(['system_key' => null, 'is_system' => false]);

        try {
            $this->map->for($invoice->fresh());
            expect()->fail('a missing receivable account should have been refused');
        } catch (InvoiceCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('receivable-account-missing');
        }
    });

    it('refuses a receivable account that is not an asset', function (): void {
        $wrong = app(CustomerService::class)->create($this->company, new CustomerData(name: 'Wrong', code: 'WRONG'));
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]], customerId: (string) $wrong->getKey());

        // `CustomerService` already refuses a non-asset at configuration time, so this is reached only if the
        // account were reclassified afterwards — planted directly, because that is the only route.
        DB::table('customers')->where('id', $wrong->getKey())
            ->update(['receivable_account_id' => $this->sales->getKey()]);

        try {
            $this->map->for($invoice->fresh());
            expect()->fail('a revenue account should not be accepted as a receivable');
        } catch (InvoiceCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('receivable-account-wrong-type');
        }
    });
});

describe('discounts', function (): void {
    it('posts revenue net of a line discount', function (): void {
        $invoice = app(SalesInvoiceService::class)->createDraft($this->company, new SalesInvoiceData(
            customerId: (string) $this->customer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [new SalesInvoiceLineData(
                description: 'Discounted',
                quantity: '1',
                unitPrice: '1000.00',
                revenueAccountId: (string) $this->sales->getKey(),
                discountPercent: '10',
            )],
        ));

        $lines = $this->map->for($invoice);

        // Revenue is what was earned, not what was listed. The discount is never a ledger line of its own here —
        // it is already netted into the line subtotal.
        expect(forAccount($lines, $this->sales)[0]->credit?->toDecimalString())->toBe('900.0000')
            ->and($lines[0]->debit?->toDecimalString())->toBe('900.0000');
    });

    it('posts revenue net of an allocated header discount', function (): void {
        $invoice = mapDraft([
            ['1', '600.00', (string) $this->sales->getKey()],
            ['1', '400.00', (string) $this->services->getKey()],
        ], headerDiscount: '100.00');

        $lines = $this->map->for($invoice);

        // Allocated 60/40 by subtotal, so revenue posts 540 and 360 — and the two still sum to the debit.
        expect(forAccount($lines, $this->sales)[0]->credit?->toDecimalString())->toBe('540.0000')
            ->and(forAccount($lines, $this->services)[0]->credit?->toDecimalString())->toBe('360.0000')
            ->and($lines[0]->debit?->toDecimalString())->toBe('900.0000');
    });

    it('taxes the discounted amount, not the gross', function (): void {
        mapTaxCode('VAT', '18');

        $invoice = mapDraft([
            ['1', '600.00', (string) $this->sales->getKey(), 'VAT'],
            ['1', '400.00', (string) $this->sales->getKey(), 'VAT'],
        ], headerDiscount: '100.00');

        $lines = $this->map->for($invoice);

        // 900 net at 18% is 162. Taxing the gross would overcharge the customer and overstate the liability.
        expect(forAccount($lines, $this->outputVat)[0]->credit?->toDecimalString())->toBe('162.0000');
    });
});

describe('negative lines', function (): void {
    it('nets a correction against the same account', function (): void {
        $invoice = mapDraft([
            ['1', '1000.00', (string) $this->sales->getKey()],
            ['-1', '200.00', (string) $this->sales->getKey()],
        ]);

        $lines = $this->map->for($invoice);

        expect($lines)->toHaveCount(2)
            ->and(forAccount($lines, $this->sales)[0]->credit?->toDecimalString())->toBe('800.0000');
    });

    it('flips the side when an account nets to a debit', function (): void {
        $invoice = mapDraft([
            ['1', '1000.00', (string) $this->sales->getKey()],
            ['-1', '300.00', (string) $this->services->getKey()],
        ]);

        $lines = $this->map->for($invoice);
        $services = forAccount($lines, $this->services)[0];

        // A negative credit cannot be stored — `journal_lines` requires both sides non-negative and exactly one
        // positive. A net credit against revenue is therefore a debit to revenue.
        expect($services->credit)->toBeNull()
            ->and($services->debit?->toDecimalString())->toBe('300.0000');
    });

    it('omits an account whose lines cancel exactly', function (): void {
        $invoice = mapDraft([
            ['1', '1000.00', (string) $this->sales->getKey()],
            ['1', '250.00', (string) $this->services->getKey()],
            ['-1', '250.00', (string) $this->services->getKey()],
        ]);

        $lines = $this->map->for($invoice);

        // Nothing to post, and a zero-sided line could not be stored anyway.
        expect(forAccount($lines, $this->services))->toBeEmpty()
            ->and($lines)->toHaveCount(2);
    });
});

describe('the balance invariant', function (): void {
    it('balances a simple invoice', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        $lines = $this->map->for($invoice);

        expect(debitTotal($lines)->toDecimalString())->toBe(creditTotal($lines)->toDecimalString());
    });

    it('balances across every shape at once', function (): void {
        mapTaxCode('VAT', '18');
        mapTaxCode('LOW', '8');
        mapTaxCode('LEVY', '2', (string) $this->payeePayable->getKey());
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'EXEMPT',
            name: 'Exempt',
            taxType: TaxType::Exempt,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        // No header discount alongside the negative line: Milestone 4 refuses to allocate one when any line is
        // non-positive, because a share of a discount against a credit line has no defensible meaning. The
        // allocated-discount case is covered on its own above.
        $invoice = mapDraft([
            ['3', '333.33', (string) $this->sales->getKey(), 'VAT'],
            ['7', '111.11', (string) $this->sales->getKey(), 'LOW'],
            ['1', '999.99', (string) $this->services->getKey(), 'LEVY'],
            ['2', '49.99', (string) $this->services->getKey(), 'EXEMPT'],
            ['1', '777.77', (string) $this->otherIncome->getKey()],
            ['-1', '55.55', (string) $this->otherIncome->getKey(), 'VAT'],
        ]);

        $lines = $this->map->for($invoice);

        $debits = debitTotal($lines);
        $credits = creditTotal($lines);

        // Mixed rates, several output accounts, an exempt line, an untaxed line and a negative line — the shape
        // most likely to lose a cent somewhere.
        expect($debits->toDecimalString())->toBe($credits->toDecimalString())
            ->and($debits->minus($credits)->isZero())->toBeTrue();
    });

    it('proves receivable equals total and revenue plus tax equals total', function (): void {
        mapTaxCode('VAT', '18');

        $invoice = mapDraft([
            ['3', '333.33', (string) $this->sales->getKey(), 'VAT'],
            ['1', '111.11', (string) $this->services->getKey()],
        ], headerDiscount: '50.00');

        $lines = $this->map->for($invoice);
        $currency = $invoice->currency_code;

        $receivable = forAccount($lines, $this->tradeReceivables)[0]->debit;
        $revenueAndTax = creditTotal($lines, $currency);

        // The three statements the milestone brief asks for, in one place: AR equals the invoice total, the credits
        // equal the total, and therefore debits equal credits.
        expect($receivable?->toDecimalString())->toBe(Money::of($invoice->total, $currency)->toDecimalString())
            ->and($revenueAndTax->toDecimalString())->toBe(Money::of($invoice->total, $currency)->toDecimalString());
    });

    it('sums rounded line taxes rather than rounding a summed total', function (): void {
        mapTaxCode('VAT', '18');

        // 33.33 at 18% is 5.9994, which the draft rounded to 6.00 per line. Three such lines total 18.00. Rounding
        // the sum instead — 99.99 at 18% = 17.9982, rounded 18.00 — happens to agree here, but the map must take
        // the stored figures either way, and `tax_total` is what the invoice showed the customer.
        $invoice = mapDraft([
            ['1', '33.33', (string) $this->sales->getKey(), 'VAT'],
            ['1', '33.33', (string) $this->sales->getKey(), 'VAT'],
            ['1', '33.33', (string) $this->sales->getKey(), 'VAT'],
        ]);

        $lines = $this->map->for($invoice);

        expect(forAccount($lines, $this->outputVat)[0]->credit?->toDecimalString())->toBe($invoice->tax_total)
            ->and(debitTotal($lines)->toDecimalString())->toBe(creditTotal($lines)->toDecimalString());
    });

    it('never drops or duplicates an amount when grouping', function (): void {
        mapTaxCode('VAT', '18');

        $invoice = mapDraft([
            ['1', '11.11', (string) $this->sales->getKey(), 'VAT'],
            ['1', '22.22', (string) $this->sales->getKey(), 'VAT'],
            ['1', '33.33', (string) $this->services->getKey(), 'VAT'],
            ['1', '44.44', (string) $this->services->getKey()],
        ]);

        $lines = $this->map->for($invoice);
        $currency = $invoice->currency_code;

        // Grouped revenue must equal the invoice subtotal exactly, and grouped tax the tax total. An entry whose
        // credits fall short of its debit is not a rounding problem — it is revenue that has vanished.
        $revenue = Money::zero($currency);
        foreach ([$this->sales, $this->services] as $account) {
            foreach (forAccount($lines, $account) as $line) {
                $revenue = $line->credit !== null ? $revenue->plus($line->credit) : $revenue->minus($line->debit);
            }
        }

        expect($revenue->toDecimalString())->toBe($invoice->subtotal)
            ->and(forAccount($lines, $this->outputVat)[0]->credit?->toDecimalString())->toBe($invoice->tax_total);
    });
});

describe('refusals', function (): void {
    it('refuses an invoice with no lines', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);
        DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())->delete();

        expect(fn () => $this->map->for($invoice->fresh()))
            ->toThrow(InvoiceCannotBePosted::class);
    });

    it('refuses a revenue account made non-postable after drafting', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        DB::table('accounts')->where('id', $this->sales->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        try {
            $this->map->for($invoice->fresh());
            expect()->fail('a non-postable revenue account should have been refused');
        } catch (InvoiceCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('posting-account-not-postable');
        }
    });

    it('refuses a revenue account that is not income', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        // Reclassified after drafting. Posting revenue to a bank account would balance and be entirely wrong.
        DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())
            ->update(['revenue_account_id' => $this->bank->getKey()]);

        try {
            $this->map->for($invoice->fresh());
            expect()->fail('a bank account should not be accepted as revenue');
        } catch (InvoiceCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('revenue-account-wrong-type');
        }
    });

    it('refuses a tax code whose output account has been cleared', function (): void {
        $taxCode = mapTaxCode('VAT', '18');
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey(), 'VAT']]);

        DB::table('tax_codes')->where('id', $taxCode->getKey())->update(['rate' => '0.0000', 'output_account_id' => null]);

        try {
            $this->map->for($invoice->fresh());
            expect()->fail('tax with nowhere to post should have been refused');
        } catch (InvoiceCannotBePosted $exception) {
            // Refused rather than dropped: the debit already includes the tax, so silently omitting the credit
            // would unbalance the entry — and if it somehow balanced, the return would understate what is owed.
            expect($exception->problemCode())->toBe('tax-output-account-missing');
        }
    });

    it('refuses a tax output account made non-postable after drafting', function (): void {
        mapTaxCode('VAT', '18');
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey(), 'VAT']]);

        DB::table('accounts')->where('id', $this->outputVat->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        expect(fn () => $this->map->for($invoice->fresh()))
            ->toThrow(InvoiceCannotBePosted::class);
    });

    it('refuses a tax output account reclassified out of liabilities', function (): void {
        mapTaxCode('VAT', '18');
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey(), 'VAT']]);

        // `TaxCodeService` refuses to configure a non-liability, so this state is only reachable afterwards —
        // and it is reachable, because the chart of accounts permits reclassifying an account that has no
        // postings, which a newly created output account does not.
        DB::table('accounts')->where('id', $this->outputVat->getKey())
            ->update(['type' => 'income', 'normal_balance' => 'credit']);

        try {
            $this->map->for($invoice->fresh());
            expect()->fail('output tax posting to income should have been refused');
        } catch (InvoiceCannotBePosted $exception) {
            // Both sides still credit, so the entry would balance and the trial balance would tie. The only
            // visible symptom is a VAT return understating what is owed, months later.
            expect($exception->problemCode())->toBe('tax-output-account-wrong-type');
        }
    });
});

describe('cross-company isolation', function (): void {
    it('refuses a revenue account belonging to a sibling company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        // Row level security cannot catch this — both companies share a tenant, so the policy is satisfied by
        // either one's accounts. Only comparing the company stops an invoice posting into a sibling's ledger.
        DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())
            ->update(['revenue_account_id' => mapAccount('4100', (string) $second->getKey())->getKey()]);

        try {
            $this->map->for($invoice->fresh());
            expect()->fail('a sibling company’s account should have been refused');
        } catch (InvoiceCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('posting-account-outside-company');
        }
    });

    it('refuses a receivable account belonging to a sibling company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $customer = app(CustomerService::class)->create($this->company, new CustomerData(name: 'X', code: 'X1'));
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]], customerId: (string) $customer->getKey());

        DB::table('customers')->where('id', $customer->getKey())
            ->update(['receivable_account_id' => mapAccount('1130', (string) $second->getKey())->getKey()]);

        expect(fn () => $this->map->for($invoice->fresh()))
            ->toThrow(InvoiceCannotBePosted::class);
    });

    it('resolves each company’s own receivable account', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);

        // Same system key, different account per company — which is exactly what the key is for.
        expect($this->map->receivableAccountFor($invoice)->company_id)->toBe($this->company->getKey())
            ->and(mapAccount('1130', (string) $second->getKey())->system_key)->toBe(Account::TRADE_RECEIVABLES);
    });
});

describe('the map writes nothing', function (): void {
    it('leaves the invoice, its lines and the ledger untouched', function (): void {
        mapTaxCode('VAT', '18');
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey(), 'VAT']]);

        $before = [
            'invoice' => DB::table('sales_invoices')->where('id', $invoice->getKey())->first(),
            'lines' => DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())->get()->toArray(),
            'entries' => DB::table('journal_entries')->count(),
            'journalLines' => DB::table('journal_lines')->count(),
            'sequences' => DB::table('document_sequences')->count(),
        ];

        $this->map->for($invoice);

        // The whole point of Stage 2: the accounting shape can be got wrong here at no cost. No document number is
        // reserved, no entry is written, and the invoice's stored tax snapshot is not mutated.
        expect(DB::table('sales_invoices')->where('id', $invoice->getKey())->first())->toEqual($before['invoice'])
            ->and(DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())->get()->toArray())->toEqual($before['lines'])
            ->and(DB::table('journal_entries')->count())->toBe($before['entries'])
            ->and(DB::table('journal_lines')->count())->toBe($before['journalLines'])
            ->and(DB::table('document_sequences')->count())->toBe($before['sequences']);
    });

    it('still leaves nothing behind when it refuses', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);
        DB::table('accounts')->where('id', $this->sales->getKey())->update(['is_active' => false, 'archived_at' => now()]);

        $entries = DB::table('journal_entries')->count();

        try {
            $this->map->for($invoice->fresh());
        } catch (InvoiceCannotBePosted) {
            // Expected.
        }

        expect(DB::table('journal_entries')->count())->toBe($entries)
            ->and(DB::table('sales_invoices')->where('id', $invoice->getKey())->value('status'))->toBe('draft');
    });
});

describe('tenant isolation', function (): void {
    it('cannot resolve accounts across tenants', function (): void {
        $invoice = mapDraft([['1', '1000.00', (string) $this->sales->getKey()]]);
        $acmeInvoiceId = (string) $invoice->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // The invoice itself is invisible from the other workspace, so the map has nothing to act on.
        expect(SalesInvoice::query()->find($acmeInvoiceId))->toBeNull();
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('sales_invoices'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
