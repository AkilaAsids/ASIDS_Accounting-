<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Domain\Contracts\CompliancePackContract;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes a tax code.
 *
 * A service querying models directly, following Accounting and Milestone 2 rather than Phase 1's
 * repositories.
 *
 * Two responsibilities are worth naming because they are not obvious from the method list.
 *
 * **Cross-company validation.** Row level security keeps one tenant out of another's data, and it does
 * nothing about a company reaching for its sibling's accounts inside the same workspace — the two live
 * under one `tenant_id`, so the policy is satisfied. Every account this service accepts is therefore
 * checked against the owning company explicitly. RLS is the outer wall, not the only one.
 *
 * **Account types.** A CHECK constraint cannot join to `accounts`, so the database can require that a
 * charging rate *has* an output account but not that the account is a liability. Pointing output VAT at
 * a revenue account would credit income with the tax and still leave the trial balance tying — nothing
 * downstream would notice, and the VAT return would understate what is owed. That check has to live
 * here, and it is expressed against `AccountType` rather than against the starter chart's codes, because
 * a company may rename or renumber its accounts freely.
 */
final readonly class TaxCodeService
{
    /**
     * Postgres's SQLSTATE for an exclusion-constraint violation — what the overlap check fires under,
     * checked alongside the constraint name in `isOverlapViolation()`.
     */
    private const string EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private TaxRateUsageProbe $usage,
        private CompliancePackContract $compliance,
    ) {}

    public function create(Company $company, TaxCodeData $data, ?string $createdById = null): TaxCode
    {
        $this->assertRegimeIsSupported($data->taxType);

        $rate = $this->assertRate($data->rate, $data->taxType);
        $this->assertRange($data->effectiveFrom, $data->effectiveTo);

        return DB::transaction(function () use ($company, $data, $rate, $createdById): TaxCode {
            $taxCode = new TaxCode;

            $taxCode->company_id = $company->getKey();
            $taxCode->code = $this->assertCodeShape($data->code);
            $taxCode->name = $data->name;
            $taxCode->tax_type = $data->taxType;
            $taxCode->rate = $rate;
            $taxCode->output_account_id = $this->resolveOutputAccountId($company, $data->outputAccountId, $rate);
            $taxCode->input_account_id = $this->resolveInputAccountId($company, $data->inputAccountId);
            $taxCode->effective_from = $data->effectiveFrom;
            $taxCode->effective_to = $data->effectiveTo;
            $taxCode->notes = $data->notes;

            // Set explicitly rather than left to the column default, so an unsaved instance reads back
            // the same as a saved one under `Model::shouldBeStrict()` — the trap Phase 1 hit on
            // `must_change_password` and Phase 2 hit again on `is_closed`.
            $taxCode->is_active = $data->isActive;
            $taxCode->created_by_id = $createdById;

            return $this->save($taxCode);
        });
    }

    /**
     * Change a tax code.
     *
     * Takes an array rather than a DTO, following `ChartOfAccountsService::update()`, because
     * `array_key_exists()` is what distinguishes "leave this alone" from "set this to null". That
     * distinction is not academic here: reopening a closed rate range means setting `effective_to` back
     * to null, and a signature that could not express it would make a closed range permanent.
     *
     * Recognised keys: `name`, `notes`, `code`, `tax_type`, `rate`, `output_account_id`,
     * `input_account_id`, `effective_from`, `effective_to`, `is_active`. Anything else is ignored rather
     * than rejected, matching how the chart of accounts behaves.
     *
     * `rate` and `effective_from` are refused once the row has been applied to a document. See A5: a rate
     * an invoice has already used is a historical fact, and the way to change what a code charges going
     * forward is a new row with a new range.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(TaxCode $taxCode, array $attributes): TaxCode
    {
        $applied = $this->usage->hasBeenApplied($taxCode);

        if ($applied) {
            $this->assertHistoricalFieldsUnchanged($taxCode, $attributes);
        }

        $type = array_key_exists('tax_type', $attributes)
            ? ($attributes['tax_type'] instanceof TaxType ? $attributes['tax_type'] : TaxType::from((string) $attributes['tax_type']))
            : $taxCode->tax_type;

        if (array_key_exists('tax_type', $attributes)) {
            $this->assertRegimeIsSupported($type);
        }

        $rate = array_key_exists('rate', $attributes)
            ? $this->assertRate((string) $attributes['rate'], $type)
            : $this->assertRate($taxCode->rate, $type);

        $from = array_key_exists('effective_from', $attributes)
            ? CarbonImmutable::parse((string) $attributes['effective_from'])->startOfDay()
            : $taxCode->effective_from->startOfDay();

        // The one place the omitted/null distinction earns its keep: `effective_to => null` reopens the
        // range, while omitting the key leaves whatever end date is already there.
        $to = array_key_exists('effective_to', $attributes)
            ? ($attributes['effective_to'] === null
                ? null
                : CarbonImmutable::parse((string) $attributes['effective_to'])->startOfDay())
            : ($taxCode->effective_to?->startOfDay());

        $this->assertRange($from, $to);

        return DB::transaction(function () use ($taxCode, $attributes, $type, $rate, $from, $to): TaxCode {
            $taxCode->fill(array_intersect_key($attributes, array_flip(['name', 'notes'])));

            if (array_key_exists('code', $attributes)) {
                $taxCode->code = $this->assertCodeShape((string) $attributes['code']);
            }

            $taxCode->tax_type = $type;
            $taxCode->rate = $rate;
            $taxCode->effective_from = $from;
            $taxCode->effective_to = $to;

            if (array_key_exists('output_account_id', $attributes)) {
                $taxCode->output_account_id = $this->resolveOutputAccountId(
                    $taxCode->company,
                    $attributes['output_account_id'] === null ? null : (string) $attributes['output_account_id'],
                    $rate,
                );
            } elseif ($taxCode->output_account_id === null && bccomp($rate, '0', 4) > 0) {
                // The rate has just risen above zero on a code that had nowhere to post it. Caught here
                // rather than left to the CHECK, so the message names the missing field.
                throw BusinessRuleViolation::make(
                    'output-account-required',
                    'This rate now charges tax, so it needs an output tax account to post it to.',
                );
            }

            if (array_key_exists('input_account_id', $attributes)) {
                $taxCode->input_account_id = $this->resolveInputAccountId(
                    $taxCode->company,
                    $attributes['input_account_id'] === null ? null : (string) $attributes['input_account_id'],
                );
            }

            if (array_key_exists('is_active', $attributes)) {
                $taxCode->is_active = (bool) $attributes['is_active'];
            }

            return $this->save($taxCode);
        });
    }

    /**
     * Close a rate's range so a successor can take over.
     *
     * The intended way to change what a code charges: end the current row on the day before the new rate
     * starts, then create the successor. Offered as its own method because doing it through `update()`
     * means remembering which date goes where, and an off-by-one leaves either a gap no document can
     * resolve or an overlap the database refuses.
     */
    public function endRange(TaxCode $taxCode, CarbonImmutable $lastEffectiveDay): TaxCode
    {
        if ($lastEffectiveDay->lessThan($taxCode->effective_from->startOfDay())) {
            throw BusinessRuleViolation::make(
                'range-ends-before-it-starts',
                sprintf(
                    'Tax code %s starts on %s, so it cannot end on %s.',
                    $taxCode->code,
                    $taxCode->effective_from->toDateString(),
                    $lastEffectiveDay->toDateString(),
                ),
            );
        }

        return DB::transaction(function () use ($taxCode, $lastEffectiveDay): TaxCode {
            $taxCode->effective_to = $lastEffectiveDay;

            return $this->save($taxCode);
        });
    }

    /**
     * Stop offering a code without deleting it.
     *
     * Deactivating does not end the range. A code inactive for new documents still holds its dates,
     * because an invoice already issued under it must still resolve the rate it was charged.
     */
    public function deactivate(TaxCode $taxCode): TaxCode
    {
        if (! $taxCode->is_active) {
            throw BusinessRuleViolation::make(
                'tax-code-already-inactive',
                sprintf('Tax code %s is already inactive.', $taxCode->code),
            );
        }

        $taxCode->is_active = false;

        return $this->save($taxCode);
    }

    public function reactivate(TaxCode $taxCode): TaxCode
    {
        if ($taxCode->is_active) {
            throw BusinessRuleViolation::make(
                'tax-code-already-active',
                sprintf('Tax code %s is already active.', $taxCode->code),
            );
        }

        $taxCode->is_active = true;

        return $this->save($taxCode);
    }

    /**
     * Remove a code created in error.
     *
     * Soft-deleted, and refused once anything has been posted under it: the document's tax has to stay
     * explicable, which means the row it cited has to stay resolvable. Deactivating is the ordinary path
     * and this is only for a genuine mistake.
     */
    public function delete(TaxCode $taxCode): void
    {
        if ($this->usage->hasBeenApplied($taxCode)) {
            throw BusinessRuleViolation::make(
                'tax-code-in-use',
                sprintf(
                    'Tax code %s has been applied to a document and cannot be deleted. Deactivate it instead — '
                    .'the documents that used it have to stay explicable.',
                    $taxCode->code,
                ),
            );
        }

        $taxCode->delete();
    }

    /**
     * Bring a soft-deleted code back.
     *
     * The exclusion constraint ignores deleted rows, so the code and range a deleted row held may have
     * been taken since. Checked here so the caller gets a conflict naming the clash rather than a raw
     * constraint violation — the same failure Milestone 2's customer restore had, and the same fix.
     */
    public function restore(TaxCode $taxCode): TaxCode
    {
        if (! $taxCode->trashed()) {
            throw BusinessRuleViolation::make(
                'tax-code-not-deleted',
                sprintf('Tax code %s is not deleted, so there is nothing to restore.', $taxCode->code),
            );
        }

        return DB::transaction(function () use ($taxCode): TaxCode {
            $clashes = TaxCode::query()
                ->forCompany($taxCode->company_id)
                ->withCode($taxCode->code)
                ->whereKeyNot($taxCode->getKey())
                ->get()
                ->contains(fn (TaxCode $other): bool => $this->rangesOverlap($taxCode, $other));

            if ($clashes) {
                throw new ResourceConflict(
                    message: sprintf(
                        'Tax code %s already has a rate covering %s, so this one cannot be restored alongside it. '
                        .'End the other range first, or restore this rate over different dates.',
                        $taxCode->code,
                        $taxCode->effective_from->toDateString(),
                    ),
                    problemCode: 'tax-code-range-taken-on-restore',
                    context: ['code' => $taxCode->code, 'tax_code_id' => $taxCode->getKey()],
                );
            }

            $taxCode->restore();

            return $taxCode;
        });
    }

    /**
     * Persist, turning the exclusion constraint into a domain conflict.
     *
     * The overlap rule lives in the database because it has to — a service check is racy, and a bulk
     * import bypasses the service entirely. But a caller should never see `23P01` or a constraint name,
     * so the violation is translated here into the conflict it actually is. The constraint stays the
     * authority; this only changes how its refusal reads.
     */
    private function save(TaxCode $taxCode): TaxCode
    {
        try {
            $taxCode->save();
        } catch (QueryException $exception) {
            if ($this->isOverlapViolation($exception)) {
                throw new ResourceConflict(
                    message: sprintf(
                        'Tax code %s already has a rate covering part of %s to %s. A rate change is a new range: '
                        .'end the existing one first.',
                        $taxCode->code,
                        $taxCode->effective_from->toDateString(),
                        $taxCode->effective_to?->toDateString() ?? 'onwards',
                    ),
                    problemCode: 'tax-code-range-overlaps',
                    context: ['code' => $taxCode->code],
                );
            }

            throw $exception;
        }

        return $taxCode;
    }

    /**
     * Whether this failure is the range-overlap exclusion constraint `save()` exists to catch.
     *
     * The constraint name is matched as a substring of the whole driver message, which embeds the
     * bound values — a payload that happened to contain the literal constraint name would otherwise
     * misclassify an unrelated `QueryException` as this conflict. An exclusion constraint (not a unique
     * one) has no dedicated Laravel exception class the way `UniqueConstraintViolationException` does
     * for 23505, so the SQLSTATE itself — Postgres's `23P01`, exclusion_violation — is checked alongside
     * the name via `getCode()`, the same technique `RowLevelSecurityBootstrapper::apply()` uses to tell
     * one SQLSTATE from another.
     */
    private function isOverlapViolation(QueryException $exception): bool
    {
        return $exception->getCode() === self::EXCLUSION_VIOLATION
            && str_contains($exception->getMessage(), 'tax_codes_no_overlapping_rates');
    }

    private function rangesOverlap(TaxCode $one, TaxCode $other): bool
    {
        $oneEnd = $one->effective_to;
        $otherEnd = $other->effective_to;

        // Inclusive at both ends, matching the database's `'[]'` ranges.
        $oneStartsAfterOtherEnds = $otherEnd !== null && $one->effective_from->greaterThan($otherEnd);
        $otherStartsAfterOneEnds = $oneEnd !== null && $other->effective_from->greaterThan($oneEnd);

        return ! $oneStartsAfterOtherEnds && ! $otherStartsAfterOneEnds;
    }

    /**
     * The jurisdiction's view of whether this regime exists.
     *
     * AN EMPTY LIST MEANS "NO RESTRICTION DECLARED", NOT "NOTHING ALLOWED".
     *
     * That reading is load-bearing and easy to get backwards. `NullCompliancePack` — which every company
     * resolves to today, Sri Lanka included — returns `[]` because no pack has enumerated its regimes
     * yet, not because the country forbids all tax. Treating the empty list as a deny-all would refuse
     * every tax code the product can currently create.
     *
     * A pack that returns a non-empty list is making a positive statement, and anything outside it is
     * refused. So the Sri Lankan pack, when it lands, restricts by listing — and until then the
     * `TaxType` enum and the database CHECK remain the constraint.
     */
    private function assertRegimeIsSupported(TaxType $type): void
    {
        $supported = $this->compliance->supportedTaxRegimes();

        if ($supported === []) {
            return;
        }

        if (! in_array($type->regime(), $supported, true)) {
            throw BusinessRuleViolation::make(
                'tax-regime-not-supported',
                sprintf(
                    '%s does not recognise %s as a tax regime.',
                    $this->compliance->displayName(),
                    $type->label(),
                ),
            );
        }
    }

    /**
     * The rate as a percentage, checked before the database has to and normalised to the ledger's scale.
     *
     * Normalising matters for more than tidiness. The audit trail records what the service assigned, not
     * what the column ended up holding, so assigning the raw `'18'` produced a trail entry reading `18`
     * beside a column reading `18.0000` — and resubmitting `18.00` would then look like a change when
     * nothing had changed. `bcadd` at scale 4 makes one canonical form, using exact decimal arithmetic
     * rather than a float round-trip.
     *
     * @return numeric-string
     */
    private function assertRate(string $rate, TaxType $type): string
    {
        $trimmed = trim($rate);

        if (! is_numeric($trimmed)) {
            throw BusinessRuleViolation::make(
                'tax-rate-not-a-number',
                sprintf('"%s" is not a number, so it cannot be a tax rate.', $rate),
            );
        }

        if (bccomp($trimmed, '0', 4) < 0) {
            throw BusinessRuleViolation::make('negative-tax-rate', 'A tax rate cannot be negative.');
        }

        // A percentage, so a hundred is the ceiling. The check exists because a rate entered as basis
        // points — 1800 for 18% — would otherwise multiply every invoice by eighteen.
        if (bccomp($trimmed, '100', 4) > 0) {
            throw BusinessRuleViolation::make(
                'tax-rate-above-one-hundred',
                sprintf(
                    'A tax rate is a percentage, so %s is out of range. 18%% is entered as 18, not 1800.',
                    $trimmed,
                ),
            );
        }

        if (! $type->allowsNonZeroRate() && bccomp($trimmed, '0', 4) !== 0) {
            throw BusinessRuleViolation::make(
                'zero-rate-type-with-rate',
                sprintf('A %s code charges nothing by definition, so its rate must be zero.', $type->label()),
            );
        }

        /** @var numeric-string $normalised */
        $normalised = bcadd($trimmed, '0', 4);

        return $normalised;
    }

    private function assertRange(CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        if ($to !== null && $to->lessThan($from)) {
            throw BusinessRuleViolation::make(
                'effective-range-inverted',
                sprintf(
                    'A rate cannot end on %s when it starts on %s.',
                    $to->toDateString(),
                    $from->toDateString(),
                ),
            );
        }
    }

    private function assertCodeShape(string $code): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw BusinessRuleViolation::make('tax-code-blank', 'A tax code cannot be blank.');
        }

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertHistoricalFieldsUnchanged(TaxCode $taxCode, array $attributes): void
    {
        $requestedRate = array_key_exists('rate', $attributes) ? trim((string) $attributes['rate']) : null;

        // Only compared when it is actually a number. A non-numeric rate is rejected moments later by
        // `assertRate()` with a message naming the field, and comparing it here would mean deciding
        // whether "eighteen" differs from 18.0000 — a question with no useful answer.
        if ($requestedRate !== null
            && is_numeric($requestedRate)
            && bccomp($requestedRate, $taxCode->rate, 4) !== 0) {
            throw new ResourceConflict(
                message: sprintf(
                    'Tax code %s at %s%% has already been applied to a document, so its rate cannot change. '
                    .'End this range and add a new range carrying the new rate.',
                    $taxCode->code,
                    $taxCode->rate,
                ),
                problemCode: 'tax-rate-already-applied',
                context: ['code' => $taxCode->code, 'rate' => $taxCode->rate],
            );
        }

        if (array_key_exists('effective_from', $attributes)) {
            $requested = CarbonImmutable::parse((string) $attributes['effective_from'])->startOfDay();

            if (! $requested->equalTo($taxCode->effective_from->startOfDay())) {
                throw new ResourceConflict(
                    message: sprintf(
                        'Tax code %s has already been applied to a document, so the date its rate took effect '
                        .'cannot move. Documents were priced against it.',
                        $taxCode->code,
                    ),
                    problemCode: 'tax-rate-start-already-applied',
                    context: ['code' => $taxCode->code],
                );
            }
        }
    }

    /**
     * The account tax charged under this code posts to.
     *
     * Must be a liability of the same company. Output tax is money held on the authority's behalf, so a
     * revenue account would credit it to income — leaving the trial balance tying and the return
     * understated, which is the worst combination: wrong and invisible.
     *
     * @param  numeric-string  $rate  already validated and normalised by `assertRate()`
     */
    private function resolveOutputAccountId(Company $company, ?string $accountId, string $rate): ?string
    {
        if ($accountId === null) {
            if (bccomp($rate, '0', 4) > 0) {
                throw BusinessRuleViolation::make(
                    'output-account-required',
                    'A rate that charges tax needs an output tax account to post it to.',
                );
            }

            return null;
        }

        $account = $this->accountWithinCompany($company, $accountId);

        if ($account->type !== AccountType::Liability) {
            throw BusinessRuleViolation::make(
                'output-account-wrong-type',
                sprintf(
                    'Account %s is %s. Output tax is owed to the authority, so it has to post to a liability — '
                    .'anything else would leave the books tying and the return understated.',
                    $account->code,
                    $account->type->value,
                ),
            );
        }

        return $this->assertPostable($account);
    }

    /**
     * The account recoverable tax on purchases would post to.
     *
     * Must be an asset: input tax is a claim against the authority. Unused by sales, validated anyway,
     * because a wrong account configured now is a wrong account the purchasing phase inherits silently.
     */
    private function resolveInputAccountId(Company $company, ?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $account = $this->accountWithinCompany($company, $accountId);

        if ($account->type !== AccountType::Asset) {
            throw BusinessRuleViolation::make(
                'input-account-wrong-type',
                sprintf(
                    'Account %s is %s. Recoverable input tax is a claim against the authority, so it has to post '
                    .'to an asset.',
                    $account->code,
                    $account->type->value,
                ),
            );
        }

        return $this->assertPostable($account);
    }

    /**
     * The account, provided it belongs to this company.
     *
     * The check that row level security cannot make. Two companies in one workspace share a `tenant_id`,
     * so the policy is satisfied by either one's accounts — only the company comparison stops a tax code
     * posting into its sibling's ledger.
     */
    private function accountWithinCompany(Company $company, string $accountId): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw BusinessRuleViolation::make(
                'account-outside-company',
                'That account belongs to a different company, or does not exist.',
            );
        }

        return $account;
    }

    private function assertPostable(Account $account): string
    {
        if (! $account->acceptsPostings()) {
            throw BusinessRuleViolation::make(
                'account-not-postable',
                sprintf('Account %s does not accept postings, so tax could not be posted to it.', $account->code),
            );
        }

        return (string) $account->getKey();
    }
}
