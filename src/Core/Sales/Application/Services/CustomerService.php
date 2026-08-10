<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Enums\CustomerStatus;
use Asids\Core\Sales\Domain\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes a customer.
 *
 * A service querying models directly, following the Accounting module rather than Phase 1's
 * repositories — the pattern decision taken before this milestone was written.
 *
 * Two rules here depend on invoices, which do not exist yet, and both go through
 * `ReceivableBalanceProbe`: a customer with an outstanding balance cannot be archived, and one with
 * any invoice cannot be deleted. Until Milestone 5 binds a real implementation, the probe truthfully
 * reports no receivables, so the rules are inert rather than absent. Writing them now is what stops
 * them being forgotten once there is something to enforce them against.
 */
final readonly class CustomerService
{
    /**
     * Codes this service generates: `C-` and up to eighteen digits.
     *
     * Eighteen because that is what a signed bigint holds without risk, and it is nine orders of
     * magnitude more customers than any company will have. The bound exists to keep a hand-typed code
     * out of the `max()` cast, not to limit anybody.
     */
    private const string GENERATED_CODE_PATTERN = '^C-[0-9]{1,18}$';

    public function __construct(private ReceivableBalanceProbe $receivables) {}

    public function create(Company $company, CustomerData $data, ?string $createdById = null): Customer
    {
        return DB::transaction(function () use ($company, $data, $createdById): Customer {
            $code = $data->code !== null
                ? $this->assertCodeAvailable($company, $data->code)
                : $this->generateCode($company);

            $customer = new Customer;

            $customer->company_id = $company->getKey();
            $customer->branch_id = $this->resolveBranchId($company, $data->branchId);
            $customer->code = $code;
            $customer->receivable_account_id = $this->resolveReceivableAccountId($company, $data->receivableAccountId);

            // Set explicitly rather than left to the column default. An unsaved model returns null for
            // a defaulted column, and reading it back before a refresh throws under
            // `Model::shouldBeStrict()` — the trap Phase 1 hit on `must_change_password` and Phase 2
            // hit again on `is_closed`.
            $customer->status = CustomerStatus::Active;
            $customer->archived_at = null;
            $customer->created_by_id = $createdById;

            $this->applyAttributes($customer, $data);

            $customer->save();

            return $customer;
        });
    }

    /**
     * Change a customer's details.
     *
     * The code may change while nothing has been invoiced. Once an invoice exists the code appears on a
     * document the customer has, and changing it would leave two identifiers for the same account.
     */
    public function update(Customer $customer, CustomerData $data): Customer
    {
        return DB::transaction(function () use ($customer, $data): Customer {
            if ($data->code !== null && $this->normalise($data->code) !== $this->normalise($customer->code)) {
                if ($this->receivables->hasAnyInvoice($customer)) {
                    throw BusinessRuleViolation::make(
                        'customer-code-locked',
                        sprintf(
                            'Customer %s has been invoiced, so its code can no longer change. The code appears '
                            .'on documents the customer already holds.',
                            $customer->code,
                        ),
                    );
                }

                $customer->code = $this->assertCodeAvailable($customer->company, $data->code, $customer->getKey());
            }

            if ($data->branchId !== null) {
                $customer->branch_id = $this->resolveBranchId($customer->company, $data->branchId);
            }

            if ($data->receivableAccountId !== null) {
                $customer->receivable_account_id = $this->resolveReceivableAccountId(
                    $customer->company,
                    $data->receivableAccountId,
                );
            }

            $this->applyAttributes($customer, $data);

            $customer->save();

            return $customer;
        });
    }

    /**
     * Stop offering this customer on new invoices, without hiding it.
     */
    public function deactivate(Customer $customer): Customer
    {
        if ($customer->isArchived()) {
            throw BusinessRuleViolation::make(
                'customer-archived',
                sprintf('Customer %s is archived. Restore it before changing its status.', $customer->code),
            );
        }

        $customer->status = CustomerStatus::Inactive;
        $customer->save();

        return $customer;
    }

    public function reactivate(Customer $customer): Customer
    {
        $customer->status = CustomerStatus::Active;
        $customer->archived_at = null;
        $customer->save();

        return $customer;
    }

    /**
     * Hide the customer from every picker.
     *
     * Refused while money is owed, and that is the point of the rule rather than a nicety: an archived
     * customer disappears from the screens someone would use to chase the debt, so archiving one who
     * still owes is how a receivable gets quietly lost.
     */
    public function archive(Customer $customer): Customer
    {
        $outstanding = $this->receivables->outstandingBalance($customer);

        if (bccomp($outstanding, '0', 4) !== 0) {
            throw BusinessRuleViolation::make(
                'customer-has-outstanding-balance',
                sprintf(
                    'Customer %s still owes %s. Archiving would remove them from the screens used to collect '
                    .'it. Settle or write off the balance first.',
                    $customer->code,
                    $outstanding,
                ),
            );
        }

        // Both together. The CHECK constraint ties status to the timestamp, and Phase 2's period-close
        // work proved what happens when a mass update moves one and not the other.
        $customer->status = CustomerStatus::Archived;
        $customer->archived_at = now();
        $customer->save();

        return $customer;
    }

    /**
     * Remove a customer that was created in error.
     *
     * Soft-deleted, and refused outright once any invoice names the customer — including a cancelled
     * one. An invoice is a statutory record and it names its customer; the record has to outlive the
     * relationship. Archiving is the ordinary path, and this is only for a genuine mistake.
     */
    public function delete(Customer $customer): void
    {
        if ($this->receivables->hasAnyInvoice($customer)) {
            throw BusinessRuleViolation::make(
                'customer-has-invoices',
                sprintf(
                    'Customer %s has been invoiced and cannot be deleted. Archive it instead — the invoices name '
                    .'this customer and have to stay resolvable.',
                    $customer->code,
                ),
            );
        }

        $customer->delete();
    }

    /**
     * Bring a soft-deleted customer back.
     *
     * The code is what makes this more than a flag flip. The unique index excludes soft-deleted rows —
     * deliberately, so a code typed by mistake is not burned for ever — which means the code a deleted
     * customer holds can be taken by someone else in the meantime. Restoring then collides.
     *
     * Left to the database that surfaces as `UniqueConstraintViolationException`: a 500 with a
     * constraint name in it, telling the user nothing they can act on. Checked here it becomes the
     * conflict it actually is, naming the code and what to do about it.
     *
     * The check and the restore share a transaction. Without that, two restores of customers holding
     * the same code could both pass the check and the second would still hit the index — the same
     * read-then-write shape the code generator has, and one worth closing where it is cheap to.
     */
    public function restore(Customer $customer): Customer
    {
        if (! $customer->trashed()) {
            throw BusinessRuleViolation::make(
                'customer-not-deleted',
                sprintf('Customer %s is not deleted, so there is nothing to restore.', $customer->code),
            );
        }

        return DB::transaction(function () use ($customer): Customer {
            // The row being restored is soft-deleted and so already outside the default scope, but the
            // key is excluded explicitly rather than relying on that: a later change to the scope
            // should not silently turn this into a self-collision check.
            $taken = Customer::query()
                ->forCompany($customer->company_id)
                ->whereRaw('upper(code) = ?', [$this->normalise($customer->code)])
                ->whereKeyNot($customer->getKey())
                ->exists();

            if ($taken) {
                // A restore-specific message rather than `ResourceConflict::duplicate()`. The generic
                // form says the code exists, which is true and useless here: the caller did not choose
                // this code, they chose a customer, and the fix is to change one of the two codes
                // rather than to retry with a different value.
                throw new ResourceConflict(
                    message: sprintf(
                        'Customer code %s is now used by another customer, so %s cannot be restored under it. '
                        .'Change the code on one of them first.',
                        $customer->code,
                        $customer->name,
                    ),
                    problemCode: 'customer-code-taken-on-restore',
                    context: ['code' => $customer->code, 'customer_id' => $customer->getKey()],
                );
            }

            $customer->restore();

            return $customer;
        });
    }

    /**
     * The customer's outstanding balance.
     *
     * Exposed here so callers ask the service rather than reaching for the probe, which is an
     * implementation detail that changes in Milestone 5.
     *
     * @return numeric-string
     */
    public function outstandingBalance(Customer $customer): string
    {
        return $this->receivables->outstandingBalance($customer);
    }

    /**
     * Everything that is a plain value with no resolution or rule attached.
     */
    private function applyAttributes(Customer $customer, CustomerData $data): void
    {
        $customer->name = $data->name;
        $customer->legal_name = $data->legalName;
        $customer->tax_identification_number = $data->taxIdentificationNumber;
        $customer->vat_registration_number = $data->vatRegistrationNumber;
        $customer->is_vat_registered = $data->isVatRegistered;
        $customer->email = $data->email;
        $customer->phone = $data->phone;
        $customer->website = $data->website;
        $customer->address_line_1 = $data->addressLine1;
        $customer->address_line_2 = $data->addressLine2;
        $customer->city = $data->city;
        $customer->district = $data->district;
        $customer->postal_code = $data->postalCode;
        $customer->country_code = $data->countryCode !== null ? strtoupper($data->countryCode) : null;
        $customer->payment_terms_days = $data->paymentTermsDays;
        $customer->credit_limit = $this->resolveCreditLimit($data->creditLimit);
        $customer->notes = $data->notes;

        if ($data->isVatRegistered && $data->vatRegistrationNumber === null) {
            throw BusinessRuleViolation::make(
                'vat-registration-number-required',
                'A VAT-registered customer needs its VAT registration number. Invoices to a registered '
                .'customer must show it.',
            );
        }

        if ($data->paymentTermsDays < 0) {
            throw BusinessRuleViolation::make(
                'negative-payment-terms',
                'Payment terms cannot be negative — that would make an invoice due before it was issued.',
            );
        }
    }

    /**
     * The credit limit, checked to be a number before it reaches a numeric column.
     *
     * The DTO carries whatever arrived — a form field, an import row, an API payload — so "500,000" or
     * an empty-but-not-null string are both reachable here. Left unchecked they surface as a database
     * type error with no indication of which field was wrong; the message below names it.
     *
     * Zero is permitted and means something specific: this customer may not be invoiced on credit at
     * all. Null means unlimited. The two are opposite statements, which is why the column is nullable
     * rather than defaulting to zero.
     *
     * @return numeric-string|null
     */
    private function resolveCreditLimit(?string $limit): ?string
    {
        if ($limit === null) {
            return null;
        }

        $trimmed = trim($limit);

        if ($trimmed === '') {
            return null;
        }

        if (! is_numeric($trimmed)) {
            throw BusinessRuleViolation::make(
                'credit-limit-not-a-number',
                sprintf('"%s" is not a number, so it cannot be a credit limit.', $limit),
            );
        }

        if (bccomp($trimmed, '0', Money::SCALE) < 0) {
            throw BusinessRuleViolation::make(
                'negative-credit-limit',
                'A credit limit cannot be negative. Leave it empty for no limit, or set zero to refuse '
                .'credit entirely.',
            );
        }

        return $trimmed;
    }

    /**
     * @return string the code, unchanged, when it is free
     */
    private function assertCodeAvailable(Company $company, string $code, ?string $ignoreId = null): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw BusinessRuleViolation::make('customer-code-blank', 'A customer code cannot be blank.');
        }

        $taken = Customer::query()
            ->forCompany((string) $company->getKey())
            ->whereRaw('upper(code) = ?', [$this->normalise($trimmed)])
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ResourceConflict::duplicate('customer', 'code', $trimmed);
        }

        return $trimmed;
    }

    /**
     * The next `C-0001` style code for the company.
     *
     * Derived from the highest existing numeric suffix rather than a count, because a deleted customer
     * would otherwise cause the next code to collide with one already issued. Not gapless, and it does
     * not need to be — no authority audits customer codes for completeness, so paying for a row lock
     * here would buy nothing.
     */
    private function generateCode(Company $company): string
    {
        $highest = Customer::query()
            ->withTrashed()
            ->forCompany((string) $company->getKey())
            // Bounded to the digits a bigint can hold. `code` is varchar(32), so `C-` followed by
            // thirty digits is a legal customer code someone can type — and an unbounded pattern fed
            // that to the cast below, which overflowed and threw. The failure was not one bad row: it
            // broke generation for the whole company, permanently, for every later customer.
            //
            // Bounding the pattern rather than widening the cast is what makes that impossible instead
            // of merely less likely. A code too long to be a generated one is not a generated one, so
            // excluding it from the maximum is also the correct answer rather than a workaround.
            ->whereRaw('code ~ ?', [self::GENERATED_CODE_PATTERN])
            ->selectRaw('max(cast(substring(code from 3) as bigint)) as n')
            ->value('n');

        $next = ((int) $highest) + 1;

        return sprintf('C-%04d', $next);
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

    /**
     * The receivable account must belong to the company, be postable, and be an asset.
     *
     * The type check is the one that matters. Pointing a customer's receivable at a revenue account
     * would post every invoice's debit to income, and the trial balance would still tie — so nothing
     * downstream would notice.
     */
    private function resolveReceivableAccountId(Company $company, ?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw BusinessRuleViolation::make(
                'account-outside-company',
                'That receivable account belongs to a different company.',
            );
        }

        if ($account->type !== AccountType::Asset) {
            throw BusinessRuleViolation::make(
                'receivable-account-wrong-type',
                sprintf(
                    'Account %s is a %s account. A customer receivable has to be an asset, or every invoice '
                    .'would debit the wrong side of the books.',
                    $account->code,
                    $account->type->value,
                ),
            );
        }

        if (! $account->acceptsPostings()) {
            throw BusinessRuleViolation::make(
                'receivable-account-not-postable',
                sprintf('Account %s does not accept postings, so invoices could not be posted to it.', $account->code),
            );
        }

        return $accountId;
    }

    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
