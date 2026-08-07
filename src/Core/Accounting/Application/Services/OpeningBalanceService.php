<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\NormalBalance;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The balances a business arrives with.
 *
 * A company migrating from spreadsheets or another product does not start from zero: it has cash in
 * the bank, money owed to it, and stock on the shelf. Those have to enter the ledger somehow, and the
 * only honest way is a journal entry like any other — which means it must balance.
 *
 * It cannot balance on its own. The debits (assets) and credits (liabilities) a business brings do
 * not net to zero, and the difference is the owner's equity at that moment. **Opening Balance
 * Equity** is what absorbs it: a system account whose only purpose is to make the arrival balance, so
 * that every subsequent report ties. An accountant then reclassifies it — into share capital,
 * retained earnings, or a director's loan — as a normal entry, which is exactly the judgement the
 * platform should not be making for them.
 *
 * The entry is dated the day before the first period the company will trade in, so the opening
 * position belongs to no trading period and every P&L starts clean.
 */
final readonly class OpeningBalanceService
{
    public function __construct(
        private PostingService $posting,
        private ChartTemplateService $template,
        private ChartOfAccountsService $chart,
        private FiscalCalendarService $calendar,
    ) {}

    /**
     * Record a company's opening position.
     *
     * @param  array<string, string>  $balances  Account id to signed decimal amount, in the account's
     *                                           own normal direction. An asset of 500 is "500"; an
     *                                           overdraft is "-500".
     */
    public function record(
        Company $company,
        CarbonImmutable $asAt,
        array $balances,
        ?User $actor = null,
    ): JournalEntry {
        if ($balances === []) {
            throw BusinessRuleViolation::make(
                code: 'no-opening-balances',
                message: 'There are no opening balances to record.',
            );
        }

        $this->assertNotAlreadyRecorded($company);

        // Created if the chart came from somewhere other than the template — a company with no
        // opening-balance equity account has nowhere for the difference to go.
        $this->template->ensureSystemAccounts($company);

        $equity = $this->chart->systemAccount($company, Account::OPENING_BALANCE_EQUITY);

        if ($equity === null) {
            throw BusinessRuleViolation::make(
                code: 'no-opening-balance-equity-account',
                message: 'This company has no Opening Balance Equity account, so an opening position cannot be recorded.',
            );
        }

        $currency = $company->base_currency_code;

        /** @var array<string, Account> $accounts */
        $accounts = Account::query()
            ->forCompany($company->getKey())
            ->whereIn('id', array_keys($balances))
            ->get()
            ->keyBy('id')
            ->all();

        $lines = [];
        $net = Money::zero($currency);

        foreach ($balances as $accountId => $amount) {
            $account = $accounts[$accountId] ?? null;

            if ($account === null) {
                throw BusinessRuleViolation::make(
                    code: 'unknown-opening-balance-account',
                    message: 'An opening balance names an account that does not belong to this company.',
                );
            }

            if ($account->system_key === Account::OPENING_BALANCE_EQUITY) {
                // Refused rather than merged. The equity line is *derived* — accepting one would let a
                // caller declare a balancing figure that does not match the balances it is supposed to
                // balance, and the entry would then be rejected for a reason that makes no sense.
                throw BusinessRuleViolation::make(
                    code: 'opening-balance-equity-is-derived',
                    message: 'Opening Balance Equity is calculated from the other balances and cannot be entered directly.',
                );
            }

            $money = Money::of($amount, $currency)->roundedTo($company->currency_precision);

            if ($money->isZero()) {
                // Skipped rather than written. A zero opening balance is the absence of one, and a line
                // of zero would fail the one-sided check for no reason the user could act on.
                continue;
            }

            // The caller states the balance in the account's own direction; this turns it into a side.
            // A positive asset is a debit, a positive liability is a credit, and a negative of either
            // is the opposite — which is how an overdraft is expressed.
            $isDebitSide = $account->normal_balance === NormalBalance::Debit;
            $putOnDebit = $money->isNegative() ? ! $isDebitSide : $isDebitSide;
            $absolute = $money->absolute();

            $lines[] = new JournalLineData(
                accountId: $accountId,
                debit: $putOnDebit ? $absolute : null,
                credit: $putOnDebit ? null : $absolute,
                description: 'Opening balance',
            );

            // Net movement in debit terms, which is what the balancing figure has to offset.
            $net = $net->plus($putOnDebit ? $absolute : $absolute->negated());
        }

        if ($lines === []) {
            throw BusinessRuleViolation::make(
                code: 'no-opening-balances',
                message: 'Every opening balance given was zero, so there is nothing to record.',
            );
        }

        // The balancing figure. If debits exceed credits the business has more assets than
        // liabilities, and the surplus is equity — a credit.
        if (! $net->isZero()) {
            $lines[] = new JournalLineData(
                accountId: $equity->getKey(),
                debit: $net->isNegative() ? $net->absolute() : null,
                credit: $net->isNegative() ? null : $net->absolute(),
                description: 'Opening balance equity',
            );
        }

        // Dated the day before trading begins, so the opening position belongs to no trading period
        // and every P&L starts clean. The period has to exist, which is why the calendar is consulted
        // rather than assumed.
        $this->calendar->periodFor($company, $asAt);

        return DB::transaction(fn (): JournalEntry => $this->posting->postNew($company, new JournalEntryData(
            entryDate: $asAt,
            description: 'Opening balances',
            lines: $lines,
            reference: 'Opening position as at '.$asAt->toDateString(),
            documentType: DocumentType::OpeningBalance,
        ), $actor));
    }

    /**
     * Whether a company has already recorded its opening position.
     */
    public function hasBeenRecorded(Company $company): bool
    {
        return JournalEntry::query()
            ->forCompany($company->getKey())
            ->where('document_type', DocumentType::OpeningBalance->value)
            ->posted()
            ->exists();
    }

    /**
     * The opening balance entry, if one exists.
     */
    public function entryFor(Company $company): ?JournalEntry
    {
        return JournalEntry::query()
            ->forCompany($company->getKey())
            ->where('document_type', DocumentType::OpeningBalance->value)
            ->posted()
            ->with('lines')
            ->first();
    }

    private function assertNotAlreadyRecorded(Company $company): void
    {
        if (! $this->hasBeenRecorded($company)) {
            return;
        }

        // Refused rather than replaced. A second opening entry would double the company's starting
        // position, and because both entries balance, nothing would report a problem — the business
        // would simply appear to have twice the assets it started with. Correcting a wrong opening
        // position means reversing the first entry, which leaves both visible.
        throw BusinessRuleViolation::make(
            code: 'opening-balances-already-recorded',
            message: 'This company already has opening balances. Reverse the existing entry before recording new ones.',
        );
    }
}
