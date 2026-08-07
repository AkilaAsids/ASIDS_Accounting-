<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Exceptions\AccountNotPostable;
use Asids\Core\Accounting\Domain\Exceptions\PostedEntryIsImmutable;
use Asids\Core\Accounting\Domain\Exceptions\UnbalancedEntry;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\Journal;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\Models\JournalLine;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Drafting entries, and the validation every entry passes before it can be posted.
 *
 * Split from `PostingService` deliberately, along the line the permissions are split on: a
 * bookkeeper drafts, an accountant posts. Keeping the two in one class would make that boundary a
 * convention rather than a structure.
 *
 * A draft is allowed to be wrong. It can be unbalanced, incomplete, dated into a closed period —
 * a half-entered entry on screen is all of those, and refusing to save it would mean losing work
 * every time the phone rings. The rules apply at posting, which is where the entry becomes part of
 * the record.
 */
final readonly class JournalService
{
    public function __construct(private FiscalCalendarService $calendar) {}

    /**
     * The company's general journal, created on first use.
     *
     * Every company gets exactly one, enforced by a partial unique index. Created lazily rather than
     * during company provisioning so that Organization does not have to know Accounting exists.
     */
    public function generalJournal(Company $company): Journal
    {
        $existing = Journal::query()->forCompany($company->getKey())->general()->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($company): Journal {
            $journal = new Journal;

            $journal->company_id = $company->getKey();
            $journal->code = Journal::GENERAL;
            $journal->name = 'General Journal';
            $journal->description = 'Manual entries and anything without a more specific book.';
            $journal->is_general = true;
            $journal->is_system = true;
            $journal->is_active = true;
            $journal->save();

            return $journal;
        });
    }

    /**
     * Create a draft.
     *
     * The entry's period is resolved now rather than at posting, so a date with no fiscal year fails
     * while the user is still looking at the form rather than days later when someone tries to post
     * it.
     */
    public function draft(Company $company, JournalEntryData $data, ?string $createdById = null): JournalEntry
    {
        $period = $this->calendar->periodFor($company, $data->entryDate);
        $journal = $data->journalId !== null
            ? $this->resolveJournal($company, $data->journalId)
            : $this->generalJournal($company);

        $accounts = $this->resolveAccounts($company, $data->lines);

        return DB::transaction(function () use ($company, $data, $period, $journal, $accounts, $createdById): JournalEntry {
            $entry = new JournalEntry;

            $entry->company_id = $company->getKey();
            $entry->journal_id = $journal->getKey();
            $entry->fiscal_period_id = $period->getKey();
            $entry->document_type = $data->documentType;
            $entry->entry_date = $data->entryDate;
            $entry->description = $data->description;
            $entry->reference = $data->reference;
            $entry->status = JournalEntryStatus::Draft;
            $entry->reverses_entry_id = $data->reversesEntryId;
            $entry->created_by_id = $createdById;
            $entry->save();

            $this->writeLines($entry, $data->lines, $accounts, $company->base_currency_code);

            return $entry->load('lines');
        });
    }

    /**
     * Replace a draft's contents.
     *
     * Lines are replaced wholesale rather than diffed. An entry is a document, not a collection that
     * accretes: "these are its lines now" is what the user means when they save the form, and
     * matching submitted rows against stored ones by position is how a reordered line silently
     * becomes an edit of a different account.
     */
    public function updateDraft(JournalEntry $entry, JournalEntryData $data): JournalEntry
    {
        $this->assertEditable($entry);

        $company = $entry->company;
        $period = $this->calendar->periodFor($company, $data->entryDate);
        $accounts = $this->resolveAccounts($company, $data->lines);

        return DB::transaction(function () use ($entry, $data, $period, $accounts, $company): JournalEntry {
            $entry->fiscal_period_id = $period->getKey();
            $entry->entry_date = $data->entryDate;
            $entry->description = $data->description;
            $entry->reference = $data->reference;
            $entry->save();

            $entry->lines()->delete();

            $this->writeLines($entry, $data->lines, $accounts, $company->base_currency_code);

            return $entry->load('lines');
        });
    }

    /**
     * Discard a draft.
     *
     * Only a draft. A posted entry is refused here and by the database trigger — the correction for
     * a posted mistake is a reversal, which leaves both the error and its remedy visible.
     */
    public function deleteDraft(JournalEntry $entry): void
    {
        $this->assertEditable($entry);

        DB::transaction(static function () use ($entry): void {
            $entry->lines()->delete();
            $entry->delete();
        });
    }

    /**
     * Everything an entry must satisfy to be posted.
     *
     * Called by `PostingService` before it writes anything, and available on its own so a client can
     * show the problems on a draft without attempting to post it.
     *
     * @return list<JournalLine>
     */
    public function assertPostable(JournalEntry $entry): array
    {
        $currency = $entry->company->base_currency_code;

        /** @var list<JournalLine> $lines */
        $lines = $entry->lines()->with('account')->get()->all();

        if ($lines === []) {
            throw UnbalancedEntry::noLines();
        }

        if (count($lines) < 2) {
            // A single line cannot balance against anything. Caught separately from the general
            // imbalance so the message names the actual problem.
            throw UnbalancedEntry::singleLine();
        }

        $debits = Money::zero($currency);
        $credits = Money::zero($currency);

        foreach ($lines as $line) {
            $account = $line->account;

            if ($account->company_id !== $entry->company_id) {
                throw AccountNotPostable::foreignCompany();
            }

            if (! $account->is_postable) {
                throw AccountNotPostable::isHeading($account->code);
            }

            if (! $account->is_active) {
                throw AccountNotPostable::isArchived($account->code);
            }

            $debits = $debits->plus($line->debitMoney($currency));
            $credits = $credits->plus($line->creditMoney($currency));
        }

        if (! $debits->equals($credits)) {
            // The database refuses this too, at commit. This is here so the customer is told by how
            // much and on which side, rather than receiving a constraint name.
            throw UnbalancedEntry::by($debits, $credits);
        }

        return $lines;
    }

    private function resolveJournal(Company $company, string $journalId): Journal
    {
        $journal = Journal::query()->forCompany($company->getKey())->find($journalId);

        if ($journal === null || ! $journal->is_active) {
            throw BusinessRuleViolation::make(
                code: 'journal-unavailable',
                message: 'That journal does not exist for this company, or is no longer in use.',
            );
        }

        return $journal;
    }

    /**
     * Load and validate every account the entry names, in one query.
     *
     * One query rather than one per line: an entry with forty lines is normal in a payroll journal,
     * and lazy loading is prohibited outside production anyway.
     *
     * @param  list<JournalLineData>  $lines
     * @return array<string, Account>
     */
    private function resolveAccounts(Company $company, array $lines): array
    {
        if ($lines === []) {
            throw UnbalancedEntry::noLines();
        }

        $ids = array_values(array_unique(array_map(
            static fn (JournalLineData $line): string => $line->accountId,
            $lines,
        )));

        /** @var array<string, Account> $accounts */
        $accounts = Account::query()
            ->forCompany($company->getKey())
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($lines as $line) {
            $account = $accounts[$line->accountId] ?? null;

            if ($account === null) {
                // Includes the cross-company case: an account in another company is simply not in
                // this result set, and saying so specifically would confirm it exists elsewhere.
                throw AccountNotPostable::foreignCompany();
            }

            if (! $account->is_postable) {
                throw AccountNotPostable::isHeading($account->code);
            }

            if (! $account->is_active) {
                throw AccountNotPostable::isArchived($account->code);
            }

            if (! $line->isOneSided()) {
                throw BusinessRuleViolation::make(
                    code: 'line-not-one-sided',
                    message: sprintf('Each line must be either a debit or a credit. Account “%s” has neither or both.', $account->code),
                    context: ['account' => $account->code],
                );
            }

            if ($line->isNegative()) {
                throw BusinessRuleViolation::make(
                    code: 'negative-line-amount',
                    message: sprintf('Line amounts cannot be negative. Put the amount on the other side instead — account “%s”.', $account->code),
                    context: ['account' => $account->code],
                );
            }
        }

        return $accounts;
    }

    /**
     * @param  list<JournalLineData>  $lines
     * @param  array<string, Account>  $accounts
     */
    private function writeLines(JournalEntry $entry, array $lines, array $accounts, string $currency): void
    {
        $lineNumber = 1;

        foreach ($lines as $data) {
            $line = new JournalLine;

            $line->company_id = $entry->company_id;
            $line->journal_entry_id = $entry->getKey();
            $line->account_id = $data->accountId;
            $line->branch_id = $data->branchId;
            $line->line_number = $lineNumber++;

            // Rounded to the company's own precision on the way in. The ledger holds amounts that
            // exist in the currency: a line of LKR 10.0050 is not a number anyone can pay, and the
            // rounding has to happen once, here, rather than at each read.
            $precision = $entry->company->currency_precision;

            $line->debit = ($data->debit ?? Money::zero($currency))->roundedTo($precision)->toDecimalString();
            $line->credit = ($data->credit ?? Money::zero($currency))->roundedTo($precision)->toDecimalString();
            $line->description = $data->description;
            $line->save();
        }
    }

    private function assertEditable(JournalEntry $entry): void
    {
        if (! $entry->isEditable()) {
            throw PostedEntryIsImmutable::cannotEdit($entry->number ?? $entry->getKey());
        }
    }
}
