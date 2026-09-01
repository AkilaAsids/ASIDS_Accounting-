<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\Services\BillPostingMap;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Domain\Exceptions\BillCannotBePosted;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The bill posting map: a bill turned into journal lines, and nothing else — Stage 4 of Wave 7 (ADR 0019 §D).
 *
 * The payable-side mirror of `InvoicePostingMapTest`, with debits and credits swapped. The map writes nothing
 * and posts nothing, which is the point: the accounting shape of a bill can be got wrong here at no cost, and
 * proved right before Stage 5 connects it to the ledger.
 *
 *     Dr Operating Expense    (per line, grouped by expense account)
 *     Dr Input VAT Recoverable (Σ, grouped by tax_codes.input_account_id)
 *       Cr Trade Payables               (total)
 *
 * Two properties matter more than the rest: the entry must balance *exactly* (the deferred constraint on
 * `journal_lines` refuses anything else), and no amount may be dropped or duplicated by the grouping. And one
 * refusal is the reason this wave exists at all — a line whose tax code has no `input_account_id` is the first
 * production path that can fail that way (AC-3.7), and its message must name the code.
 *
 * RED expectation before Stage 4 lands: `BillPostingMap`, `BillService`, `BillData`/`BillLineData`,
 * `BillCannotBePosted`, `Bill` and `Account::TRADE_PAYABLES` do not exist, so `app(BillPostingMap::class)` in the
 * beforeEach errors every test.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->purchases = billAccount('5100');   // Expense
    $this->rent = billAccount('6200');         // Expense
    $this->utilities = billAccount('6300');    // Expense
    $this->inputVat = billAccount('1170');     // Asset — Input VAT Recoverable
    $this->prepayments = billAccount('1160');  // Asset — an alternate input account
    $this->tradePayables = billAccount('2110'); // Liability — the credit
    $this->income = billAccount('4100');       // Income — a wrong-type expense
    $this->outputVat = billAccount('2140');    // Liability — output side of a charging code

    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

    $this->map = app(BillPostingMap::class);
});

function billAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * A tax code charging the given rate, with an output account (required for a charging code) and — unless told
 * otherwise — an input account, which is what a bill posting recovers through.
 */
function billMapTaxCode(string $code, string $rate, ?string $inputAccountId = 'default', ?string $outputAccountId = null): void
{
    app(TaxCodeService::class)->create(test()->company, new TaxCodeData(
        code: $code,
        name: $code.' tax',
        taxType: TaxType::Vat,
        rate: $rate,
        effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        outputAccountId: $outputAccountId ?? (string) test()->outputVat->getKey(),
        inputAccountId: $inputAccountId === 'default' ? (string) test()->inputVat->getKey() : $inputAccountId,
    ));
}

/**
 * A draft bill from line specs. Each call gets a fresh supplier-invoice-number so the duplicate guard is quiet.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3?: string|null}>  $specs  [quantity, unitPrice, expenseAccountId, taxCode]
 */
function billMapDraft(array $specs, ?string $headerDiscount = null): Bill
{
    static $counter = 0;
    $counter++;

    $lines = array_map(
        static fn (array $spec, int $index): BillLineData => new BillLineData(
            description: 'Line '.($index + 1),
            quantity: $spec[0],
            unitPrice: $spec[1],
            expenseAccountId: $spec[2],
            taxCode: $spec[3] ?? null,
        ),
        $specs,
        array_keys($specs),
    );

    return app(BillService::class)->createDraft(test()->company, new BillData(
        supplierId: (string) test()->supplier->getKey(),
        billDate: CarbonImmutable::parse('2026-06-15'),
        supplierInvoiceNumber: 'SUP-'.$counter,
        lines: $lines,
        discountAmount: $headerDiscount,
    ));
}

/**
 * @param  list<JournalLineData>  $lines
 */
function billDebitTotal(array $lines, string $currency = 'LKR'): Money
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
function billCreditTotal(array $lines, string $currency = 'LKR'): Money
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
function billForAccount(array $lines, Account $account): array
{
    return array_values(array_filter(
        $lines,
        static fn (JournalLineData $line): bool => $line->accountId === (string) $account->getKey(),
    ));
}

describe('the simplest bill', function (): void {
    it('produces one expense debit and one payable credit', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        $lines = $this->map->for($bill);

        // Debits first, the single payable credit last (§D — a purchase reads debits-first).
        expect($lines)->toHaveCount(2)
            ->and($lines[0]->accountId)->toBe((string) $this->purchases->getKey())
            ->and($lines[0]->debit?->toDecimalString())->toBe('1000.0000')
            ->and($lines[1]->accountId)->toBe((string) $this->tradePayables->getKey())
            ->and($lines[1]->credit?->toDecimalString())->toBe('1000.0000');
    });

    it('credits payables the bill total, not the subtotal', function (): void {
        billMapTaxCode('VAT', '18');
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey(), 'VAT']]);

        $lines = $this->map->for($bill);

        // The company owes the tax as well as the goods. Crediting the subtotal would understate the payable by
        // exactly the tax and leave the entry unbalanced.
        expect(billForAccount($lines, $this->tradePayables)[0]->credit?->toDecimalString())->toBe('1180.0000')
            ->and($bill->total)->toBe('1180.0000');
    });

    it('debits both expense and input VAT on a taxed bill', function (): void {
        billMapTaxCode('VAT', '18');
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey(), 'VAT']]);

        $lines = $this->map->for($bill);

        expect($lines)->toHaveCount(3)
            ->and(billForAccount($lines, $this->purchases)[0]->debit?->toDecimalString())->toBe('1000.0000')
            ->and(billForAccount($lines, $this->inputVat)[0]->debit?->toDecimalString())->toBe('180.0000')
            ->and(billForAccount($lines, $this->tradePayables)[0]->credit?->toDecimalString())->toBe('1180.0000');
    });

    it('names the supplier on every line', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        foreach ($this->map->for($bill) as $line) {
            expect($line->description)->toContain($this->supplier->name);
        }
    });
});

describe('grouping expense', function (): void {
    it('collapses two lines sharing one expense account into a single debit', function (): void {
        $bill = billMapDraft([
            ['1', '600.00', (string) $this->purchases->getKey()],
            ['1', '400.00', (string) $this->purchases->getKey()],
        ]);

        $lines = $this->map->for($bill);
        $expense = billForAccount($lines, $this->purchases);

        expect($expense)->toHaveCount(1)
            ->and($expense[0]->debit?->toDecimalString())->toBe('1000.0000')
            ->and($lines)->toHaveCount(2);
    });

    it('keeps distinct expense accounts as separate debits', function (): void {
        $bill = billMapDraft([
            ['1', '600.00', (string) $this->purchases->getKey()],
            ['1', '400.00', (string) $this->rent->getKey()],
        ]);

        $lines = $this->map->for($bill);

        expect($lines)->toHaveCount(3)
            ->and(billForAccount($lines, $this->purchases)[0]->debit?->toDecimalString())->toBe('600.0000')
            ->and(billForAccount($lines, $this->rent)[0]->debit?->toDecimalString())->toBe('400.0000');
    });

    it('orders debits by account code, then the payable credit last', function (): void {
        $bill = billMapDraft([
            ['1', '100.00', (string) $this->rent->getKey()],       // 6200
            ['1', '100.00', (string) $this->purchases->getKey()],  // 5100
        ]);

        $codes = array_map(
            fn (JournalLineData $line): string => Account::query()->whereKey($line->accountId)->value('code'),
            $this->map->for($bill),
        );

        // Expense debits ascending by code, then Trade Payables — so a printed entry reads the way one would be
        // written, and two runs over the same bill produce identical output.
        expect($codes)->toBe(['5100', '6200', '2110']);
    });
});

describe('grouping input tax', function (): void {
    it('collapses lines sharing one input account into a single tax debit', function (): void {
        billMapTaxCode('VAT', '18');
        billMapTaxCode('VAT2', '18');

        $bill = billMapDraft([
            ['1', '1000.00', (string) $this->purchases->getKey(), 'VAT'],
            ['1', '1000.00', (string) $this->rent->getKey(), 'VAT2'],
        ]);

        $tax = billForAccount($this->map->for($bill), $this->inputVat);

        // Grouped by the account, not the code: two codes pointing at one input account produce one debit.
        expect($tax)->toHaveCount(1)
            ->and($tax[0]->debit?->toDecimalString())->toBe('360.0000');
    });

    it('keeps distinct input accounts as separate tax debits', function (): void {
        billMapTaxCode('VAT', '18');
        billMapTaxCode('LEVY', '2', inputAccountId: (string) $this->prepayments->getKey());

        $bill = billMapDraft([
            ['1', '1000.00', (string) $this->purchases->getKey(), 'VAT'],
            ['1', '1000.00', (string) $this->purchases->getKey(), 'LEVY'],
        ]);

        $lines = $this->map->for($bill);

        expect(billForAccount($lines, $this->inputVat)[0]->debit?->toDecimalString())->toBe('180.0000')
            ->and(billForAccount($lines, $this->prepayments)[0]->debit?->toDecimalString())->toBe('20.0000');
    });

    it('handles mixed rates by summing what each line was charged', function (): void {
        billMapTaxCode('VAT', '18');
        billMapTaxCode('LOW', '8');

        $bill = billMapDraft([
            ['1', '1000.00', (string) $this->purchases->getKey(), 'VAT'],
            ['1', '1000.00', (string) $this->purchases->getKey(), 'LOW'],
        ]);

        expect(billForAccount($this->map->for($bill), $this->inputVat)[0]->debit?->toDecimalString())->toBe('260.0000');
    });

    it('produces no input line for a zero-rated bill', function (): void {
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'ZERO', name: 'Zero rated', taxType: TaxType::ZeroRated, rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey(), 'ZERO']]);

        // Zero-rated is reportable on a return but recovers no input tax.
        expect($this->map->for($bill))->toHaveCount(2)
            ->and(billForAccount($this->map->for($bill), $this->inputVat))->toBeEmpty();
    });

    it('produces no input line for a line with no tax code at all', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        expect($this->map->for($bill))->toHaveCount(2);
    });
});

describe('the payable account', function (): void {
    it('resolves by the trade payables system key', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        // Resolved by key, never by code — a company may renumber its chart freely. No per-supplier override
        // this wave (Gate-1 dec. 3).
        expect($this->map->payableAccountFor($bill)->getKey())->toBe($this->tradePayables->getKey())
            ->and($this->map->payableAccountFor($bill)->system_key)->toBe(Account::TRADE_PAYABLES);
    });

    it('refuses when the payable account has been made non-postable', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        DB::table('accounts')->where('id', $this->tradePayables->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        expect(fn () => $this->map->for($bill->fresh()))
            ->toThrow(BillCannotBePosted::class);
    });

    it('refuses when no payable account exists at all', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        DB::table('accounts')->where('id', $this->tradePayables->getKey())
            ->update(['system_key' => null, 'is_system' => false]);

        try {
            $this->map->for($bill->fresh());
            expect()->fail('a missing payable account should have been refused');
        } catch (BillCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('payable-account-missing');
        }
    });

    it('refuses a payable account that is not a liability', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        // Reclassified after drafting — the only route, since provisioning types 2110 a liability.
        DB::table('accounts')->where('id', $this->tradePayables->getKey())
            ->update(['type' => 'asset', 'normal_balance' => 'debit']);

        try {
            $this->map->for($bill->fresh());
            expect()->fail('a non-liability payable should have been refused');
        } catch (BillCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('payable-account-wrong-type');
        }
    });
});

describe('discounts', function (): void {
    it('posts expense net of a line discount', function (): void {
        $bill = app(BillService::class)->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'DISC-1',
            lines: [new BillLineData(
                description: 'Discounted',
                quantity: '1',
                unitPrice: '1000.00',
                expenseAccountId: (string) $this->purchases->getKey(),
                discountPercent: '10',
            )],
        ));

        $lines = $this->map->for($bill);

        expect(billForAccount($lines, $this->purchases)[0]->debit?->toDecimalString())->toBe('900.0000')
            ->and(billForAccount($lines, $this->tradePayables)[0]->credit?->toDecimalString())->toBe('900.0000');
    });

    it('taxes the discounted amount, not the gross', function (): void {
        billMapTaxCode('VAT', '18');

        $bill = billMapDraft([
            ['1', '600.00', (string) $this->purchases->getKey(), 'VAT'],
            ['1', '400.00', (string) $this->purchases->getKey(), 'VAT'],
        ], headerDiscount: '100.00');

        // 900 net at 18% is 162. Taxing the gross would overstate the recoverable input tax.
        expect(billForAccount($this->map->for($bill), $this->inputVat)[0]->debit?->toDecimalString())->toBe('162.0000');
    });
});

describe('negative lines', function (): void {
    it('nets a correction against the same account', function (): void {
        $bill = billMapDraft([
            ['1', '1000.00', (string) $this->purchases->getKey()],
            ['-1', '200.00', (string) $this->purchases->getKey()],
        ]);

        $lines = $this->map->for($bill);

        expect($lines)->toHaveCount(2)
            ->and(billForAccount($lines, $this->purchases)[0]->debit?->toDecimalString())->toBe('800.0000');
    });

    it('flips the side when an expense account nets to a credit', function (): void {
        $bill = billMapDraft([
            ['1', '1000.00', (string) $this->purchases->getKey()],
            ['-1', '300.00', (string) $this->rent->getKey()],
        ]);

        $rent = billForAccount($this->map->for($bill), $this->rent)[0];

        // A negative debit cannot be stored — a net credit against an expense is a credit to that expense.
        expect($rent->debit)->toBeNull()
            ->and($rent->credit?->toDecimalString())->toBe('300.0000');
    });
});

describe('the balance invariant', function (): void {
    it('balances a simple bill', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        $lines = $this->map->for($bill);

        expect(billDebitTotal($lines)->toDecimalString())->toBe(billCreditTotal($lines)->toDecimalString());
    });

    it('balances across every shape at once', function (): void {
        billMapTaxCode('VAT', '18');
        billMapTaxCode('LOW', '8');
        billMapTaxCode('LEVY', '2', inputAccountId: (string) $this->prepayments->getKey());
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'EXEMPT', name: 'Exempt', taxType: TaxType::Exempt, rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        $bill = billMapDraft([
            ['3', '333.33', (string) $this->purchases->getKey(), 'VAT'],
            ['7', '111.11', (string) $this->purchases->getKey(), 'LOW'],
            ['1', '999.99', (string) $this->rent->getKey(), 'LEVY'],
            ['2', '49.99', (string) $this->rent->getKey(), 'EXEMPT'],
            ['1', '777.77', (string) $this->utilities->getKey()],
            ['-1', '55.55', (string) $this->utilities->getKey(), 'VAT'],
        ]);

        $lines = $this->map->for($bill);
        $debits = billDebitTotal($lines);
        $credits = billCreditTotal($lines);

        expect($debits->toDecimalString())->toBe($credits->toDecimalString())
            ->and($debits->minus($credits)->isZero())->toBeTrue();
    });

    it('proves payable equals total and expense plus input equals total', function (): void {
        billMapTaxCode('VAT', '18');

        $bill = billMapDraft([
            ['3', '333.33', (string) $this->purchases->getKey(), 'VAT'],
            ['1', '111.11', (string) $this->rent->getKey()],
        ], headerDiscount: '50.00');

        $lines = $this->map->for($bill);
        $currency = $bill->currency_code;

        $payable = billForAccount($lines, $this->tradePayables)[0]->credit;
        $expenseAndInput = billDebitTotal($lines, $currency);

        expect($payable?->toDecimalString())->toBe(Money::of($bill->total, $currency)->toDecimalString())
            ->and($expenseAndInput->toDecimalString())->toBe(Money::of($bill->total, $currency)->toDecimalString());
    });

    it('sums rounded line taxes rather than rounding a summed total', function (): void {
        billMapTaxCode('VAT', '18');

        $bill = billMapDraft([
            ['1', '33.33', (string) $this->purchases->getKey(), 'VAT'],
            ['1', '33.33', (string) $this->purchases->getKey(), 'VAT'],
            ['1', '33.33', (string) $this->purchases->getKey(), 'VAT'],
        ]);

        $lines = $this->map->for($bill);

        expect(billForAccount($lines, $this->inputVat)[0]->debit?->toDecimalString())->toBe($bill->tax_total)
            ->and(billDebitTotal($lines)->toDecimalString())->toBe(billCreditTotal($lines)->toDecimalString());
    });
});

describe('refusals', function (): void {
    it('refuses a bill with no lines', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);
        DB::table('bill_lines')->where('bill_id', $bill->getKey())->delete();

        expect(fn () => $this->map->for($bill->fresh()))
            ->toThrow(BillCannotBePosted::class);
    });

    it('refuses an expense account that is not an expense', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        // Reclassified after drafting. Posting an expense to an income account would balance and be wrong.
        DB::table('bill_lines')->where('bill_id', $bill->getKey())
            ->update(['expense_account_id' => $this->income->getKey()]);

        try {
            $this->map->for($bill->fresh());
            expect()->fail('an income account should not be accepted as an expense');
        } catch (BillCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('expense-account-wrong-type');
        }
    });

    it('refuses a line whose tax code has no input account, naming the code', function (): void {
        // THE refusal this wave exists to add (AC-3.7). A charging code with no `input_account_id` — the day-one
        // state of most tenants' VAT codes — has nowhere to post the recoverable tax.
        billMapTaxCode('VAT', '18', inputAccountId: null);
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey(), 'VAT']]);

        try {
            $this->map->for($bill->fresh());
            expect()->fail('input tax with nowhere to post should have been refused');
        } catch (BillCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('tax-input-account-missing')
                // The message names the code and the remedy — the person who hits this fixes the configuration.
                ->and($exception->getMessage())->toContain('VAT');
        }
    });

    it('refuses an input account reclassified out of assets', function (): void {
        billMapTaxCode('VAT', '18');
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey(), 'VAT']]);

        // Input VAT is recoverable — an asset. Credited elsewhere, the recovery would be misstated.
        DB::table('accounts')->where('id', $this->inputVat->getKey())
            ->update(['type' => 'liability', 'normal_balance' => 'credit']);

        try {
            $this->map->for($bill->fresh());
            expect()->fail('input VAT posting to a liability should have been refused');
        } catch (BillCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('tax-input-account-wrong-type');
        }
    });
});

describe('cross-company isolation', function (): void {
    it('refuses an expense account belonging to a sibling company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);

        // Row level security cannot catch this — both companies share a tenant. Only comparing the company stops
        // a bill posting into a sibling's ledger.
        DB::table('bill_lines')->where('bill_id', $bill->getKey())
            ->update(['expense_account_id' => billAccount('5100', (string) $second->getKey())->getKey()]);

        try {
            $this->map->for($bill->fresh());
            expect()->fail('a sibling company’s account should have been refused');
        } catch (BillCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('posting-account-outside-company');
        }
    });
});

describe('the map writes nothing', function (): void {
    it('leaves the bill, its lines and the ledger untouched', function (): void {
        billMapTaxCode('VAT', '18');
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey(), 'VAT']]);

        $before = [
            'bill' => DB::table('bills')->where('id', $bill->getKey())->first(),
            'lines' => DB::table('bill_lines')->where('bill_id', $bill->getKey())->get()->toArray(),
            'entries' => DB::table('journal_entries')->count(),
            'journalLines' => DB::table('journal_lines')->count(),
            'sequences' => DB::table('document_sequences')->count(),
        ];

        $this->map->for($bill);

        expect(DB::table('bills')->where('id', $bill->getKey())->first())->toEqual($before['bill'])
            ->and(DB::table('bill_lines')->where('bill_id', $bill->getKey())->get()->toArray())->toEqual($before['lines'])
            ->and(DB::table('journal_entries')->count())->toBe($before['entries'])
            ->and(DB::table('journal_lines')->count())->toBe($before['journalLines'])
            ->and(DB::table('document_sequences')->count())->toBe($before['sequences']);
    });

    it('still leaves nothing behind when it refuses', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);
        DB::table('accounts')->where('id', $this->purchases->getKey())->update(['is_active' => false, 'archived_at' => now()]);

        $entries = DB::table('journal_entries')->count();

        try {
            $this->map->for($bill->fresh());
        } catch (BillCannotBePosted) {
            // Expected.
        }

        expect(DB::table('journal_entries')->count())->toBe($entries)
            ->and(DB::table('bills')->where('id', $bill->getKey())->value('status'))->toBe('draft');
    });
});

describe('tenant isolation', function (): void {
    it('cannot resolve a bill across tenants', function (): void {
        $bill = billMapDraft([['1', '1000.00', (string) $this->purchases->getKey()]]);
        $acmeBillId = (string) $bill->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(Bill::query()->find($acmeBillId))->toBeNull();
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('bills'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
