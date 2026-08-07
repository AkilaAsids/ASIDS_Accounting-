<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Events\JournalEntryPosted;
use Asids\Core\Accounting\Domain\Events\JournalEntryReversed;
use Asids\Core\Accounting\Domain\Exceptions\PeriodNotOpen;
use Asids\Core\Accounting\Domain\Exceptions\PostedEntryIsImmutable;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\Models\JournalLine;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The moment an entry becomes part of the financial record.
 *
 * Everything here happens in one transaction, and the order matters:
 *
 *   1. validate — every rule, before anything is written
 *   2. check the period is open — the last thing that can legitimately refuse
 *   3. reserve a document number — inside the transaction, so a rollback returns it
 *   4. mark the entry posted
 *
 * A number reserved outside this transaction would survive a failure and leave a gap in a series a
 * tax authority may audit for completeness. That is why `DocumentNumberService` refuses to issue one
 * when no transaction is open rather than trusting callers to remember.
 *
 * After this point the entry is immutable — enforced by a database trigger, not by this class.
 * Correcting it means `reverse()`, which writes a new entry rather than touching the old one.
 */
final readonly class PostingService
{
    public function __construct(
        private JournalService $journals,
        private DocumentNumberService $numbers,
    ) {}

    /**
     * Post a draft.
     */
    public function post(JournalEntry $entry, ?User $actor = null): JournalEntry
    {
        if ($entry->isPosted()) {
            throw PostedEntryIsImmutable::alreadyPosted($entry->number ?? $entry->getKey());
        }

        // Validated before the transaction opens, so a rejected entry costs no lock and consumes no
        // number.
        $this->journals->assertPostable($entry);

        $period = $entry->fiscalPeriod;

        if (! $period->acceptsPostings()) {
            throw PeriodNotOpen::forPosting($period->label, $period->status);
        }

        $posted = DB::transaction(function () use ($entry, $period, $actor): JournalEntry {
            $entry->number = $this->numbers->next($entry->company, $entry->document_type, $period);
            $entry->status = JournalEntryStatus::Posted;
            $entry->posted_at = now();
            $entry->posted_by_id = $actor?->getKey();
            $entry->save();

            return $entry;
        });

        // Dispatched after the transaction commits, never inside it: a listener that observed an
        // entry the transaction went on to roll back would act on a document that does not exist.
        // Tranche 4 hangs balance maintenance off this.
        JournalEntryPosted::dispatch($posted, $actor);

        return $posted;
    }

    /**
     * Draft and post in one call.
     *
     * What every automated posting path uses — a payment, an invoice, the year-end close. Interactive
     * bookkeeping goes through draft-then-post so a bookkeeper can prepare an entry an accountant
     * reviews; nothing else benefits from the intermediate state.
     */
    public function postNew(
        Company $company,
        JournalEntryData $data,
        ?User $actor = null,
    ): JournalEntry {
        return DB::transaction(function () use ($company, $data, $actor): JournalEntry {
            $entry = $this->journals->draft($company, $data, $actor?->getKey());

            return $this->post($entry, $actor);
        });
    }

    /**
     * Undo a posted entry by writing its mirror image.
     *
     * The original is untouched apart from being marked reversed and pointed at its reversal. Both
     * entries stay in the ledger and cancel, which is what an auditor expects to see: the mistake,
     * and the correction, each with its own date and its own author.
     *
     * The reversal is dated in the period given — usually today's — rather than the original's,
     * because backdating a correction into a closed period is exactly what closing is meant to
     * prevent.
     */
    public function reverse(
        JournalEntry $entry,
        string $reason,
        ?CarbonImmutable $reversalDate = null,
        ?User $actor = null,
    ): JournalEntry {
        if ($entry->status === JournalEntryStatus::Draft) {
            throw PostedEntryIsImmutable::cannotReverseDraft();
        }

        if ($entry->status === JournalEntryStatus::Reversed) {
            throw PostedEntryIsImmutable::alreadyReversed($entry->number ?? $entry->getKey());
        }

        $company = $entry->company;
        $currency = $company->base_currency_code;
        $date = $reversalDate ?? CarbonImmutable::now()->startOfDay();

        /** @var list<JournalLine> $lines */
        $lines = $entry->lines()->get()->all();

        // Every line, with its sides swapped. The amounts are copied rather than recomputed, so the
        // reversal is exact by construction — a recomputation could round differently and leave a
        // residue behind.
        $mirrored = array_map(
            static fn (JournalLine $line): JournalLineData => new JournalLineData(
                accountId: $line->account_id,
                debit: $line->isDebit() ? null : $line->creditMoney($currency),
                credit: $line->isDebit() ? $line->debitMoney($currency) : null,
                branchId: $line->branch_id,
                description: $line->description,
            ),
            $lines,
        );

        $reversal = DB::transaction(function () use ($entry, $mirrored, $date, $reason, $actor, $company): JournalEntry {
            $reversal = $this->postNew($company, new JournalEntryData(
                entryDate: $date,
                description: sprintf('Reversal of %s: %s', $entry->number, $entry->description),
                lines: $mirrored,
                reference: $entry->reference,
                journalId: $entry->journal_id,
                documentType: $entry->document_type,
                reversesEntryId: $entry->getKey(),
            ), $actor);

            // The one update the immutability trigger permits on a posted entry.
            $entry->status = JournalEntryStatus::Reversed;
            $entry->reversed_by_entry_id = $reversal->getKey();
            $entry->reversed_at = now();
            $entry->reversal_reason = $reason;
            $entry->save();

            return $reversal;
        });

        JournalEntryReversed::dispatch($entry, $reversal, $actor);

        return $reversal;
    }

    /**
     * The total of a set of lines on one side. Used by callers building an entry programmatically.
     *
     * @param  list<JournalLineData>  $lines
     */
    public function totalDebits(array $lines, string $currency): Money
    {
        return array_reduce(
            $lines,
            static fn (Money $carry, JournalLineData $line): Money => $line->isDebit()
                ? $carry->plus($line->amount($currency))
                : $carry,
            Money::zero($currency),
        );
    }

    public function documentTypeFor(JournalEntry $entry): DocumentType
    {
        return $entry->document_type;
    }
}
