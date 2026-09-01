<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\Services;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Enums\SupplierStatus;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes a supplier.
 *
 * The payable-side mirror of `CustomerService`. A service querying models directly, following the
 * Accounting module rather than Phase 1's repositories.
 *
 * Three rules here depend on bills, which do not exist yet, and all go through `PayableBalanceProbe`: a
 * supplier the company still owes cannot be archived, one named by any bill cannot be deleted, and one
 * named by any bill cannot be recoded. Until Wave 7 binds a real implementation, the probe truthfully
 * reports no payables, so the rules are inert rather than absent. Writing them now is what stops them
 * being forgotten once there is something to enforce them against.
 */
final readonly class SupplierService
{
    /**
     * Codes this service generates: `S-` and up to eighteen digits.
     *
     * Eighteen because that is what a signed bigint holds without risk, and it is nine orders of
     * magnitude more suppliers than any company will have. The bound exists to keep a hand-typed code
     * out of the `max()` cast, not to limit anybody.
     */
    private const string GENERATED_CODE_PATTERN = '^S-[0-9]{1,18}$';

    public function __construct(private PayableBalanceProbe $payables) {}

    public function create(Company $company, SupplierData $data, ?string $createdById = null): Supplier
    {
        return DB::transaction(function () use ($company, $data, $createdById): Supplier {
            $code = $data->code !== null
                ? $this->assertCodeAvailable($company, $data->code)
                : $this->generateCode($company);

            $supplier = new Supplier;

            $supplier->company_id = $company->getKey();
            $supplier->branch_id = $this->resolveBranchId($company, $data->branchId);
            $supplier->code = $code;

            // Set explicitly rather than left to the column default. An unsaved model returns null for
            // a defaulted column, and reading it back before a refresh throws under
            // `Model::shouldBeStrict()` — the trap Phase 1 hit on `must_change_password` and Phase 2
            // hit again on `is_closed`.
            $supplier->status = SupplierStatus::Active;
            $supplier->archived_at = null;
            $supplier->created_by_id = $createdById;

            $this->applyAttributes($supplier, $data);

            return $this->save($supplier);
        });
    }

    /**
     * Change a supplier's details.
     *
     * Takes an array rather than a DTO, following `CustomerService::update()`, because
     * `array_key_exists()` is what distinguishes "leave this alone" from "set this to null" — the
     * distinction a whole-DTO signature cannot express. It matters here because `branch_id` is
     * legitimately clearable, and a caller who wants to clear it has no way to say so through a DTO that
     * treats null the same as omitted.
     *
     * The code may change while nothing has been billed. Once a bill exists the code appears on a
     * document the supplier has, and changing it would leave two identifiers for the same account.
     *
     * Recognised keys: `code`, `name`, `legal_name`, `tax_identification_number`,
     * `vat_registration_number`, `is_vat_registered`, `email`, `phone`, `website`, `address_line_1`,
     * `address_line_2`, `city`, `district`, `postal_code`, `country_code`, `payment_terms_days`,
     * `branch_id`, `notes`. Anything else is ignored rather than rejected.
     *
     * Every effective value is computed and every rule checked before the first assignment, so a
     * refused update leaves the in-memory model exactly as it was handed in.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Supplier $supplier, array $attributes): Supplier
    {
        $code = $supplier->code;

        if (array_key_exists('code', $attributes)) {
            $requestedCode = $attributes['code'] !== null ? (string) $attributes['code'] : '';

            if ($this->normalise($requestedCode) !== $this->normalise($supplier->code)) {
                if ($this->payables->hasAnyBill($supplier)) {
                    throw BusinessRuleViolation::make(
                        'supplier-code-locked',
                        sprintf(
                            'Supplier %s has been billed, so its code can no longer change. The code appears '
                            .'on documents the supplier already holds.',
                            $supplier->code,
                        ),
                    );
                }

                $code = $this->assertCodeAvailable($supplier->company, $requestedCode, $supplier->getKey());
            }
        }

        $branchId = array_key_exists('branch_id', $attributes)
            ? $this->resolveBranchId(
                $supplier->company,
                $attributes['branch_id'] !== null ? (string) $attributes['branch_id'] : null,
            )
            : $supplier->branch_id;

        $isVatRegistered = array_key_exists('is_vat_registered', $attributes)
            ? (bool) $attributes['is_vat_registered']
            : $supplier->is_vat_registered;

        $vatRegistrationNumber = array_key_exists('vat_registration_number', $attributes)
            ? ($attributes['vat_registration_number'] !== null ? (string) $attributes['vat_registration_number'] : null)
            : $supplier->vat_registration_number;

        if ($isVatRegistered && $vatRegistrationNumber === null) {
            throw BusinessRuleViolation::make(
                'vat-registration-number-required',
                'A VAT-registered supplier needs its VAT registration number. Bills from a registered '
                .'supplier must show it.',
            );
        }

        $paymentTermsDays = $supplier->payment_terms_days;

        if (array_key_exists('payment_terms_days', $attributes)) {
            $paymentTermsDays = (int) $attributes['payment_terms_days'];

            if ($paymentTermsDays < 0) {
                throw BusinessRuleViolation::make(
                    'negative-payment-terms',
                    'Payment terms cannot be negative — that would make a bill due before it was received.',
                );
            }
        }

        $countryCode = array_key_exists('country_code', $attributes)
            ? ($attributes['country_code'] !== null ? strtoupper((string) $attributes['country_code']) : null)
            : $supplier->country_code;

        return DB::transaction(function () use (
            $supplier,
            $attributes,
            $code,
            $branchId,
            $isVatRegistered,
            $vatRegistrationNumber,
            $paymentTermsDays,
            $countryCode,
        ): Supplier {
            $supplier->fill(array_intersect_key($attributes, array_flip([
                'name', 'legal_name', 'tax_identification_number', 'email', 'phone', 'website',
                'address_line_1', 'address_line_2', 'city', 'district', 'postal_code', 'notes',
            ])));

            $supplier->code = $code;
            $supplier->branch_id = $branchId;
            $supplier->is_vat_registered = $isVatRegistered;
            $supplier->vat_registration_number = $vatRegistrationNumber;
            $supplier->payment_terms_days = $paymentTermsDays;
            $supplier->country_code = $countryCode;

            return $this->save($supplier);
        });
    }

    /**
     * Stop offering this supplier on new bills, without hiding it.
     */
    public function deactivate(Supplier $supplier): Supplier
    {
        if ($supplier->isArchived()) {
            throw BusinessRuleViolation::make(
                'supplier-archived',
                sprintf('Supplier %s is archived. Restore it before changing its status.', $supplier->code),
            );
        }

        $supplier->status = SupplierStatus::Inactive;
        $supplier->save();

        return $supplier;
    }

    public function reactivate(Supplier $supplier): Supplier
    {
        $supplier->status = SupplierStatus::Active;
        $supplier->archived_at = null;
        $supplier->save();

        return $supplier;
    }

    /**
     * Hide the supplier from every picker.
     *
     * Refused while money is owed, and that is the point of the rule rather than a nicety: an archived
     * supplier disappears from the screens someone would use to pay the balance, so archiving one the
     * company still owes is how a payable gets quietly lost.
     */
    public function archive(Supplier $supplier): Supplier
    {
        $outstanding = $this->payables->outstandingBalance($supplier);

        if (bccomp($outstanding, '0', Money::SCALE) !== 0) {
            throw BusinessRuleViolation::make(
                'supplier-has-outstanding-balance',
                sprintf(
                    'You still owe supplier %s %s. Archiving would remove them from the screens used to pay '
                    .'it. Settle the balance first.',
                    $supplier->code,
                    $outstanding,
                ),
            );
        }

        // Both together. The CHECK constraint ties status to the timestamp, and Phase 2's period-close
        // work proved what happens when a mass update moves one and not the other.
        $supplier->status = SupplierStatus::Archived;
        $supplier->archived_at = now();
        $supplier->save();

        return $supplier;
    }

    /**
     * Remove a supplier that was created in error.
     *
     * Soft-deleted, and refused outright once any bill names the supplier — including a cancelled one. A
     * bill is a statutory record and it names its supplier; the record has to outlive the relationship.
     * Archiving is the ordinary path, and this is only for a genuine mistake.
     */
    public function delete(Supplier $supplier): void
    {
        if ($this->payables->hasAnyBill($supplier)) {
            throw BusinessRuleViolation::make(
                'supplier-has-bills',
                sprintf(
                    'Supplier %s has been billed and cannot be deleted. Archive it instead — the bills name '
                    .'this supplier and have to stay resolvable.',
                    $supplier->code,
                ),
            );
        }

        $supplier->delete();
    }

    /**
     * Bring a soft-deleted supplier back.
     *
     * The code is what makes this more than a flag flip. The unique index excludes soft-deleted rows —
     * deliberately, so a code typed by mistake is not burned for ever — which means the code a deleted
     * supplier holds can be taken by someone else in the meantime. Restoring then collides.
     *
     * Checked here rather than left to the database, where it would surface as a 500 naming a constraint,
     * so it becomes the conflict it actually is, naming the code and what to do about it.
     *
     * The check and the restore share a transaction. Without that, two restores of suppliers holding the
     * same code could both pass the check and the second would still hit the index.
     */
    public function restore(Supplier $supplier): Supplier
    {
        if (! $supplier->trashed()) {
            throw BusinessRuleViolation::make(
                'supplier-not-deleted',
                sprintf('Supplier %s is not deleted, so there is nothing to restore.', $supplier->code),
            );
        }

        return DB::transaction(function () use ($supplier): Supplier {
            // The row being restored is soft-deleted and so already outside the default scope, but the
            // key is excluded explicitly rather than relying on that: a later change to the scope should
            // not silently turn this into a self-collision check.
            $taken = Supplier::query()
                ->forCompany($supplier->company_id)
                ->whereRaw('upper(code) = ?', [$this->normalise($supplier->code)])
                ->whereKeyNot($supplier->getKey())
                ->exists();

            if ($taken) {
                // A restore-specific message rather than `ResourceConflict::duplicate()`. The generic
                // form says the code exists, which is true and useless here: the caller did not choose
                // this code, they chose a supplier, and the fix is to change one of the two codes rather
                // than to retry with a different value.
                throw new ResourceConflict(
                    message: sprintf(
                        'Supplier code %s is now used by another supplier, so %s cannot be restored under it. '
                        .'Change the code on one of them first.',
                        $supplier->code,
                        $supplier->name,
                    ),
                    problemCode: 'supplier-code-taken-on-restore',
                    context: ['code' => $supplier->code, 'supplier_id' => $supplier->getKey()],
                );
            }

            $supplier->restore();

            return $supplier;
        });
    }

    /**
     * The supplier's outstanding balance — what the company still owes it.
     *
     * Exposed here so callers ask the service rather than reaching for the probe, which is an
     * implementation detail that changes in Wave 7.
     *
     * @return numeric-string
     */
    public function outstandingBalance(Supplier $supplier): string
    {
        return $this->payables->outstandingBalance($supplier);
    }

    /**
     * Everything that is a plain value with no resolution or rule attached.
     *
     * Every rule is checked before the first assignment, so a refusal here never leaves the model
     * holding some of the requested change and none of the rest.
     */
    private function applyAttributes(Supplier $supplier, SupplierData $data): void
    {
        if ($data->isVatRegistered && $data->vatRegistrationNumber === null) {
            throw BusinessRuleViolation::make(
                'vat-registration-number-required',
                'A VAT-registered supplier needs its VAT registration number. Bills from a registered '
                .'supplier must show it.',
            );
        }

        if ($data->paymentTermsDays < 0) {
            throw BusinessRuleViolation::make(
                'negative-payment-terms',
                'Payment terms cannot be negative — that would make a bill due before it was received.',
            );
        }

        $supplier->name = $data->name;
        $supplier->legal_name = $data->legalName;
        $supplier->tax_identification_number = $data->taxIdentificationNumber;
        $supplier->vat_registration_number = $data->vatRegistrationNumber;
        $supplier->is_vat_registered = $data->isVatRegistered;
        $supplier->email = $data->email;
        $supplier->phone = $data->phone;
        $supplier->website = $data->website;
        $supplier->address_line_1 = $data->addressLine1;
        $supplier->address_line_2 = $data->addressLine2;
        $supplier->city = $data->city;
        $supplier->district = $data->district;
        $supplier->postal_code = $data->postalCode;
        $supplier->country_code = $data->countryCode !== null ? strtoupper($data->countryCode) : null;
        $supplier->payment_terms_days = $data->paymentTermsDays;
        $supplier->notes = $data->notes;
    }

    /**
     * Persist, turning the code-uniqueness race into the same conflict the pre-check already produces.
     *
     * `assertCodeAvailable()` is read-then-write: two concurrent creates (or updates) for the same code
     * can both pass it, and only one insert survives. Left to the database that surfaces as
     * `UniqueConstraintViolationException` — a 500 naming a constraint, not a supplier. Caught here it
     * becomes the same `duplicate-resource` conflict the pre-check already throws, because it is the same
     * conflict caught one layer later. The constraint stays the authority; only its refusal's shape
     * changes.
     */
    private function save(Supplier $supplier): Supplier
    {
        try {
            $supplier->save();
        } catch (QueryException $exception) {
            if ($this->isDuplicateCodeViolation($exception)) {
                throw ResourceConflict::duplicate('supplier', 'code', $supplier->code);
            }

            throw $exception;
        }

        return $supplier;
    }

    /**
     * Whether this failure is the code-uniqueness race `save()` exists to catch.
     *
     * The constraint name is matched as a substring of the whole driver message, which embeds the bound
     * values — a payload that happened to contain the literal constraint name would otherwise
     * misclassify an unrelated `QueryException` as this conflict. Requiring the exception to actually be
     * a `UniqueConstraintViolationException` (Laravel's own classification, driven by SQLSTATE 23505, not
     * by string matching) closes that gap without weakening the name check, which still identifies
     * *which* unique index fired.
     */
    private function isDuplicateCodeViolation(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            && str_contains($exception->getMessage(), 'suppliers_company_code_unique');
    }

    /**
     * @return string the code, unchanged, when it is free
     */
    private function assertCodeAvailable(Company $company, string $code, ?string $ignoreId = null): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw BusinessRuleViolation::make('supplier-code-blank', 'A supplier code cannot be blank.');
        }

        $taken = Supplier::query()
            ->forCompany((string) $company->getKey())
            ->whereRaw('upper(code) = ?', [$this->normalise($trimmed)])
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ResourceConflict::duplicate('supplier', 'code', $trimmed);
        }

        return $trimmed;
    }

    /**
     * The next `S-0001` style code for the company.
     *
     * Derived from the highest existing numeric suffix rather than a count, because a deleted supplier
     * would otherwise cause the next code to collide with one already issued. Not gapless, and it does
     * not need to be — no authority audits supplier codes for completeness, so paying for a row lock here
     * would buy nothing.
     */
    private function generateCode(Company $company): string
    {
        $highest = Supplier::query()
            ->withTrashed()
            ->forCompany((string) $company->getKey())
            // Bounded to the digits a bigint can hold. `code` is varchar(32), so `S-` followed by thirty
            // digits is a legal supplier code someone can type — and an unbounded pattern fed that to the
            // cast below, which overflowed and threw, breaking generation for the whole company
            // permanently. A code too long to be a generated one is not a generated one, so excluding it
            // from the maximum is also the correct answer rather than a workaround.
            ->whereRaw('code ~ ?', [self::GENERATED_CODE_PATTERN])
            ->selectRaw('max(cast(substring(code from 3) as bigint)) as n')
            ->value('n');

        $next = ((int) $highest) + 1;

        return sprintf('S-%04d', $next);
    }

    /**
     * The branch must belong to the same company.
     *
     * A branch from another company would be a cross-company reference — the kind that lets one
     * company's data surface in another's report.
     */
    private function resolveBranchId(Company $company, ?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        $belongs = Branch::query()
            ->where('company_id', $company->getKey())
            ->whereKey($branchId)
            ->exists();

        if (! $belongs) {
            throw BusinessRuleViolation::make(
                'branch-outside-company',
                'That branch belongs to a different company.',
            );
        }

        return $branchId;
    }

    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
