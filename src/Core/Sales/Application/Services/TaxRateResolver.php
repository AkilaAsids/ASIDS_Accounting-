<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Exceptions\AmbiguousTaxRate;
use Asids\Core\Sales\Domain\Exceptions\NoApplicableTaxRate;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Carbon\CarbonImmutable;

/**
 * Which rate applied on a given day, and what tax that produces.
 *
 * Accounting-critical, and the reason is worth stating before the code. Every invoice line's tax comes
 * from here. Resolve the wrong rate and the invoice is wrong, the customer is billed wrongly, the ledger
 * posts a wrong liability, and the VAT return understates or overstates what is owed — all of it
 * internally consistent, all of it balancing, none of it detectable downstream.
 *
 * So this class is built to fail rather than to guess. Three refusals are deliberate:
 *
 * **It never returns a default.** No rate found is an exception, not 0%. A silent zero produces an
 * invoice that looks right, posts a balanced entry, ties in the trial balance, and misstates a return.
 *
 * **It never picks one of several.** If two rows cover the same date it raises rather than calling
 * `first()`, which would let the query planner decide a company's tax rate. The database constraint makes
 * that unreachable; the check stays because an unreachable branch that fails loudly costs nothing and an
 * unreachable branch that guesses is how a ledger quietly goes wrong.
 *
 * **It never falls back to another code.** A missing `VAT` is not silently served by `ZERO`.
 *
 * Selection is by company and date, never by "the newest row". Newest is right for the common case and
 * wrong for the one that matters — an invoice being corrected months later must resolve the rate that
 * applied on its own date, not today's.
 */
final readonly class TaxRateResolver
{
    /**
     * The rate row governing this code on this date.
     *
     * Case-insensitive on the code, matching the exclusion constraint's `upper(code)`: a lookup treating
     * `vat` and `VAT` as different codes would miss the very row the database considers a duplicate.
     *
     * Soft-deleted rows are excluded by the model's global scope, which is correct — a deleted rate never
     * applied to anything, and the exclusion constraint has already released its dates.
     *
     * @throws NoApplicableTaxRate when nothing covers the date, or what covers it is inactive
     * @throws AmbiguousTaxRate when more than one row covers the date
     */
    public function resolve(Company $company, string $code, CarbonImmutable $date): TaxCode
    {
        $day = $date->startOfDay();

        // Every row for the code, then filtered in PHP rather than by a date predicate in SQL. Two
        // reasons: an inclusive-both-ends comparison against a nullable upper bound is easy to get subtly
        // wrong in SQL and easy to read here, and fetching them all is what makes the ambiguity check and
        // the inactive-versus-absent distinction possible at all. A company has a handful of rows per
        // code, so the cost is nil.
        $candidates = TaxCode::query()
            ->forCompany((string) $company->getKey())
            ->withCode($code)
            ->get();

        if ($candidates->isEmpty()) {
            throw NoApplicableTaxRate::forCode(trim($code), $day);
        }

        $covering = $candidates->filter(
            static fn (TaxCode $taxCode): bool => $taxCode->coversDate($day),
        )->values();

        if ($covering->isEmpty()) {
            // The code exists; its ranges simply leave this date uncovered. Reported distinctly because
            // the remedy differs — usually a gap left by ending one range and forgetting the next.
            throw NoApplicableTaxRate::outsideEveryRange(trim($code), $day);
        }

        if ($covering->count() > 1) {
            throw AmbiguousTaxRate::forCode(trim($code), $day, $covering->count());
        }

        /** @var TaxCode $resolved */
        $resolved = $covering->first();

        if (! $resolved->is_active) {
            // Distinguished from "not found" on purpose. The rate exists and covers the date, and the user
            // needs to know it was deliberately withdrawn rather than never configured — one is reactivated,
            // the other created.
            throw NoApplicableTaxRate::becauseInactive($resolved->code, $day);
        }

        return $resolved;
    }

    /**
     * The tax on a net amount, at the rate applying on the date.
     *
     * The convenience the callers will actually use, kept here so resolution and application cannot drift
     * apart into two places that disagree about which row governs a date.
     */
    public function taxOn(
        Money $net,
        Company $company,
        string $code,
        CarbonImmutable $date,
        ?int $precision = null,
    ): Money {
        return $this->applyTo($net, $this->resolve($company, $code, $date), $precision);
    }

    /**
     * The tax a specific rate row produces on a net amount.
     *
     * All arithmetic through `Money`, which works in scaled integers throughout — no float touches an
     * amount at any point. `multipliedBy()` rounds half away from zero to the ledger's scale of four,
     * which is the behaviour a tax authority expects and the behaviour the rest of the ledger already
     * uses.
     *
     * `$precision` rounds further, for the ordinary case where a document is presented and settled in a
     * currency with two decimal places. Passing null leaves the result at the ledger's scale. Which of
     * those an invoice wants is the invoice's decision, not this class's — a line total rounded to the
     * currency is what makes a printed document add up, and that belongs with the document.
     */
    public function applyTo(Money $net, TaxCode $taxCode, ?int $precision = null): Money
    {
        $tax = $net->multipliedBy($taxCode->rateFactor());

        return $precision === null ? $tax : $tax->roundedTo($precision);
    }
}
