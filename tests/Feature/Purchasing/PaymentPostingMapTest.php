<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Purchasing\Application\Services\PaymentPostingMap;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBePosted;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * The payment posting map: a payment turned into journal lines, and nothing else — Stage 3 of Wave 8a (ADR
 * 0020 §B).
 *
 * The payable-side settlement is the exact inverse of `BillPostingMap`'s payable-side creation, and vastly
 * simpler: a payment has no lines and no tax, so it is always exactly two lines —
 *
 *     Dr Trade Payables (total)
 *         Cr Bank/Cash (total)
 *
 * — balanced by construction (full allocation makes `amount = Σ allocations`, so the single debit and the
 * single credit are the same figure). `PaymentPostingMap::for()` takes primitives, not a persisted
 * `SupplierPayment`, because it runs *before* the payment exists (§C4 step 9) — a payment is recorded and
 * posted in one step, so there is no draft row to load the way `BillPostingMap::for(Bill $bill)` loads one.
 *
 * The map writes nothing and posts nothing, so a refusal here costs no lock and no number.
 *
 * RED expectation before Stage 3 lands: `PaymentPostingMap` and `PaymentCannotBePosted` do not exist, so
 * `app(PaymentPostingMap::class)` in the `beforeEach` errors every test.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->bank = paymentMapAccount('1120');        // Asset — Bank Accounts (caller-named, keyless)
    $this->cash = paymentMapAccount('1110');        // Asset — Cash in Hand (an alternate caller-named account)
    $this->tradePayables = paymentMapAccount('2110'); // Liability — the debit, resolved by key
    $this->income = paymentMapAccount('4100');      // Income — a wrong-type "bank account" to refuse

    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

    $this->map = app(PaymentPostingMap::class);
});

function paymentMapAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * @param  list<JournalLineData>  $lines
 */
function mapDebitTotal(array $lines, string $currency = 'LKR'): Money
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
function mapCreditTotal(array $lines, string $currency = 'LKR'): Money
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
function mapForAccount(array $lines, Account $account): array
{
    return array_values(array_filter(
        $lines,
        static fn (JournalLineData $line): bool => $line->accountId === (string) $account->getKey(),
    ));
}

describe('the simplest payment', function (): void {
    it('produces exactly one payable debit and one bank credit, payable first', function (): void {
        $lines = $this->map->for($this->company, $this->bank, Money::of('1000.00', 'LKR'), $this->supplier);

        // Trade Payables (the debit) first, Bank/Cash (the credit) last — the order the ADR's own worked
        // example renders the entry in (§B), the mirror of a purchase reading debits-first.
        expect($lines)->toHaveCount(2)
            ->and($lines[0]->accountId)->toBe((string) $this->tradePayables->getKey())
            ->and($lines[0]->debit?->toDecimalString())->toBe('1000.0000')
            ->and($lines[0]->credit)->toBeNull()
            ->and($lines[1]->accountId)->toBe((string) $this->bank->getKey())
            ->and($lines[1]->credit?->toDecimalString())->toBe('1000.0000')
            ->and($lines[1]->debit)->toBeNull();
    });

    it('names the supplier on both lines', function (): void {
        $lines = $this->map->for($this->company, $this->bank, Money::of('500.00', 'LKR'), $this->supplier);

        foreach ($lines as $line) {
            expect($line->description)->toContain($this->supplier->name);
        }
    });

    it('posts whatever bank/cash account is named', function (): void {
        $lines = $this->map->for($this->company, $this->cash, Money::of('250.00', 'LKR'), $this->supplier);

        expect(mapForAccount($lines, $this->cash))->toHaveCount(1)
            ->and(mapForAccount($lines, $this->bank))->toBeEmpty();
    });
});

describe('the balance invariant', function (): void {
    it('balances by construction for any amount', function (): void {
        $lines = $this->map->for($this->company, $this->bank, Money::of('1234.56', 'LKR'), $this->supplier);

        expect(mapDebitTotal($lines)->toDecimalString())->toBe(mapCreditTotal($lines)->toDecimalString())
            ->and(mapDebitTotal($lines)->toDecimalString())->toBe('1234.5600');
    });
});

describe('the payable account', function (): void {
    it('resolves by the trade payables system key, never by code', function (): void {
        // No per-supplier override this wave (Gate-1 dec. 6) — every payment debits this one account.
        expect($this->map->payableAccountFor($this->company)->getKey())->toBe($this->tradePayables->getKey())
            ->and($this->map->payableAccountFor($this->company)->system_key)->toBe(Account::TRADE_PAYABLES);
    });

    it('refuses when the payable account has been made non-postable', function (): void {
        DB::table('accounts')->where('id', $this->tradePayables->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        expect(fn () => $this->map->for($this->company, $this->bank, Money::of('1000.00', 'LKR'), $this->supplier))
            ->toThrow(PaymentCannotBePosted::class);
    });

    it('refuses when no payable account exists at all', function (): void {
        DB::table('accounts')->where('id', $this->tradePayables->getKey())
            ->update(['system_key' => null, 'is_system' => false]);

        try {
            $this->map->for($this->company, $this->bank, Money::of('1000.00', 'LKR'), $this->supplier);
            expect()->fail('a missing payable account should have been refused');
        } catch (PaymentCannotBePosted $exception) {
            // The exact code is not pinned here — see the QA report — but the message must still name the
            // company and the remedy, mirroring `BillCannotBePosted::withoutPayableAccount`.
            expect($exception->getMessage())->toContain($this->company->name);
        }
    });

    it('refuses a payable account that is not a liability', function (): void {
        DB::table('accounts')->where('id', $this->tradePayables->getKey())
            ->update(['type' => 'asset', 'normal_balance' => 'debit']);

        try {
            $this->map->for($this->company, $this->bank, Money::of('1000.00', 'LKR'), $this->supplier);
            expect()->fail('a non-liability payable should have been refused');
        } catch (PaymentCannotBePosted $exception) {
            expect($exception->getMessage())->toContain($this->tradePayables->code);
        }
    });
});

describe('the bank/cash account', function (): void {
    it('refuses a bank account that is not of type Asset, naming the stable problem code', function (): void {
        // The ADR gives this exact code (§C4): `payment-bank-account-wrong-type` — the one new refusal with no
        // bill analogue, and confirmed verbatim, unlike the shared account-resolution refusals above.
        try {
            $this->map->for($this->company, $this->income, Money::of('1000.00', 'LKR'), $this->supplier);
            expect()->fail('a non-asset bank account should have been refused');
        } catch (PaymentCannotBePosted $exception) {
            expect($exception->problemCode())->toBe('payment-bank-account-wrong-type')
                ->and($exception->getMessage())->toContain($this->income->code);
        }
    });

    it('refuses a bank account that has been made non-postable', function (): void {
        DB::table('accounts')->where('id', $this->bank->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        expect(fn () => $this->map->for($this->company, $this->bank, Money::of('1000.00', 'LKR'), $this->supplier))
            ->toThrow(PaymentCannotBePosted::class);
    });

    it('refuses a bank account belonging to a different company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $siblingBank = paymentMapAccount('1120', (string) $second->getKey());

        // Row level security cannot catch this — both companies share a tenant. Only comparing the company
        // stops a payment posting into a sibling's ledger (mirror `BillPostingMap`, ADR §B).
        expect(fn () => $this->map->for($this->company, $siblingBank, Money::of('1000.00', 'LKR'), $this->supplier))
            ->toThrow(PaymentCannotBePosted::class);
    });
});

describe('the map writes nothing', function (): void {
    it('leaves the ledger and the sequence table untouched, on success and on refusal', function (): void {
        $before = [
            'entries' => DB::table('journal_entries')->count(),
            'journalLines' => DB::table('journal_lines')->count(),
            'sequences' => DB::table('document_sequences')->count(),
        ];

        $this->map->for($this->company, $this->bank, Money::of('1000.00', 'LKR'), $this->supplier);

        try {
            $this->map->for($this->company, $this->income, Money::of('1000.00', 'LKR'), $this->supplier);
        } catch (PaymentCannotBePosted) {
            // Expected.
        }

        expect(DB::table('journal_entries')->count())->toBe($before['entries'])
            ->and(DB::table('journal_lines')->count())->toBe($before['journalLines'])
            ->and(DB::table('document_sequences')->count())->toBe($before['sequences']);
    });
});
