<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\Services\DocumentNumberService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Purchasing\Application\DTOs\PaymentData;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Domain\Enums\PaymentStatus;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBePosted;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBeRecorded;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\PaymentAllocation;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;
use Asids\Core\Sales\Application\Services\LedgerNarration;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Recording supplier payments and settling their bills — the whole record-and-post lifecycle in one step
 * (ADR 0020 §C4). The payable-side mirror of `BillService::post()`, with two structural differences the
 * born-posted, no-draft lifecycle forces.
 *
 * NO SEPARATE POST
 * ----------------
 * A bill is drafted and later posted; a payment records cash that has already moved, so `record()` does both
 * at once (Gate-1 dec. 5). There is no `createDraft`/`post` pair, no `Draft` status, no update path — a
 * payment is born posted and immutable (§A5).
 *
 * THE FIRST MULTI-DOCUMENT TRANSACTION
 * ------------------------------------
 * A payment may allocate across several outstanding bills in one transaction — the first document in the
 * codebase that locks more than one existing posted row. The bills are locked in ascending id order, always
 * before any sequence number is drawn, which is what makes concurrent payments over overlapping bill sets
 * deadlock-free (§F1). The pre-transaction outstanding/cap checks are advisory; the locked re-read is the
 * authority, exactly as `BillService::post()` treats its single-row lock (`BillService.php:248-258`).
 *
 * WHAT REFUSES, AND WHERE
 * -----------------------
 * Everything that can refuse runs before anything is reserved — mirror `BillService::post()`. Input and
 * allocation problems raise `PaymentCannotBeRecorded`; account-resolution and closed-period problems raise
 * `PaymentCannotBePosted`, the same division bills draw. The posting map writes nothing, so an account refusal
 * costs no lock and no number, and a closed-period refusal is checked before the transaction opens.
 *
 * THE STRICT-MODE / LAZY-LOADING TRAPS
 * ------------------------------------
 * `record()` sets every attribute on the new `SupplierPayment` explicitly — never leaning on a column default
 * — so an unsaved instance reads back the same as a saved one under `Model::shouldBeStrict()` (§A7, the trap
 * Phase 1 hit on `must_change_password`).
 */
final readonly class PaymentService
{
    public function __construct(
        private PaymentPostingMap $postingMap,
        private PostingService $posting,
        private DocumentNumberService $numbers,
        private FiscalCalendarService $calendar,
    ) {}

    /**
     * Record a supplier payment, allocate it across the named bills, and post it — all in one transaction.
     */
    public function record(Company $company, PaymentData $data, ?User $actor = null): SupplierPayment
    {
        // ── Pre-transaction: free refusals, no lock and no number ──────────────────────────────────

        // 1. The supplier — ownership only. Unlike a bill, a payment does NOT call `acceptsNewBills()`
        //    (AC-1.10): paying what you already owe an archived or dormant supplier is allowed; only new
        //    billing is refused.
        $supplier = $this->resolveSupplier($company, $data->supplierId);

        // 2. The caller-named bank/cash account — ownership only here; type and postability are the posting
        //    map's job (§B), re-checked there before the transaction opens.
        $bankAccount = $this->resolveBankAccount($company, $data->bankAccountId);

        // 3. The branch, if one is named.
        $branchId = $this->resolveBranchId($company, $data->branchId);

        // 4. There must be something to allocate (AC-1.2).
        if ($data->allocations === []) {
            throw PaymentCannotBeRecorded::withoutAllocations();
        }

        // 5. The stated total must be positive (AC-1.8).
        $amount = $this->assertDecimal($data->amount);

        if (bccomp($amount, '0', Money::SCALE) <= 0) {
            throw PaymentCannotBeRecorded::amountNotPositive($amount);
        }

        // 6. Each allocation positive, and no bill named twice (the readable message; the unique index §A4 is
        //    the authority under a race).
        $seen = [];
        $allocated = '0.0000';

        foreach ($data->allocations as $allocation) {
            $allocationAmount = $this->assertDecimal($allocation->amount);

            if (bccomp($allocationAmount, '0', Money::SCALE) <= 0) {
                throw PaymentCannotBeRecorded::allocationNotPositive($allocation->billId, $allocationAmount);
            }

            if (isset($seen[$allocation->billId])) {
                throw PaymentCannotBeRecorded::duplicateBillAllocation($allocation->billId);
            }

            $seen[$allocation->billId] = true;
            $allocated = bcadd($allocated, $allocationAmount, Money::SCALE);
        }

        // 7. The target bills — each belongs to this company (AC-1.5), to the named supplier (AC-1.3), is
        //    outstanding (AC-1.4), and its allocation does not exceed its current `amount_due` (AC-1.6, the
        //    advisory pre-check; re-read authoritatively under the lock at step 12).
        $bills = $this->loadBills($company, array_keys($seen));

        foreach ($data->allocations as $allocation) {
            $bill = $bills[$allocation->billId] ?? null;

            if ($bill === null) {
                throw PaymentCannotBeRecorded::billOutsideCompany($allocation->billId);
            }

            if ((string) $bill->supplier_id !== (string) $supplier->getKey()) {
                throw PaymentCannotBeRecorded::billNotForSupplier($allocation->billId);
            }

            if (! $bill->status->isOutstanding()) {
                throw PaymentCannotBeRecorded::billNotOutstanding($bill->number ?? $allocation->billId, $bill->status);
            }

            if (bccomp($this->assertDecimal($allocation->amount), $bill->amount_due, Money::SCALE) > 0) {
                throw PaymentCannotBeRecorded::allocationExceedsDue(
                    $bill->number ?? $allocation->billId,
                    $this->assertDecimal($allocation->amount),
                    $bill->amount_due,
                );
            }
        }

        // 8. Full allocation: Σ allocations == the stated total (AC-1.7, dec. 2).
        if (bccomp($allocated, $amount, Money::SCALE) !== 0) {
            throw PaymentCannotBeRecorded::notFullyAllocated($amount, $allocated);
        }

        // 9. The posting lines — the map writes nothing, so an account refusal here (`PaymentCannotBePosted`)
        //    costs no lock.
        $lines = $this->postingMap->for($company, $bankAccount, Money::of($amount, $company->base_currency_code), $supplier);

        // 10. The calendar, before any number is reserved (AC-2.7).
        $period = $this->calendar->periodFor($company, $data->paymentDate->startOfDay());

        if (! $period->acceptsPostings()) {
            throw PaymentCannotBePosted::intoClosedPeriod($period->label, $period->status);
        }

        // ── Inside the transaction: everything commits together ────────────────────────────────────
        return DB::transaction(function () use (
            $company,
            $data,
            $supplier,
            $bankAccount,
            $branchId,
            $amount,
            $lines,
            $period,
            $actor,
        ): SupplierPayment {
            // 11. Lock every target bill in ascending id order — the deadlock-free order (§F1), acquired
            //     before any sequence number is drawn.
            $billIds = array_map(static fn ($allocation): string => $allocation->billId, $data->allocations);

            /** @var EloquentCollection<int, Bill> $locked */
            $locked = Bill::query()
                ->whereKey($billIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Amount per bill, keyed by id (duplicates already refused at step 6).
            $amountFor = [];
            foreach ($data->allocations as $allocation) {
                $amountFor[$allocation->billId] = $this->assertDecimal($allocation->amount);
            }

            // 12. Re-validate each locked bill — the only checks that hold under concurrency: still
            //     outstanding (a racing payment may have paid it), and the per-bill cap re-read now.
            foreach ($locked as $bill) {
                if (! $bill->status->isOutstanding()) {
                    throw PaymentCannotBeRecorded::billNotOutstanding((string) ($bill->number ?? $bill->getKey()), $bill->status);
                }

                if (bccomp($amountFor[(string) $bill->getKey()], $bill->amount_due, Money::SCALE) > 0) {
                    throw PaymentCannotBeRecorded::allocationExceedsDue(
                        (string) ($bill->number ?? $bill->getKey()),
                        $amountFor[(string) $bill->getKey()],
                        $bill->amount_due,
                    );
                }
            }

            // 13. Pre-assign the payment id so `SourceDocument::for()` has a key without the row being saved
            //     (§C6), draw the gapless PAY number, then post the JV citing the payment.
            $payment = new SupplierPayment;
            $payment->id = $payment->newUniqueId();

            $number = $this->numbers->next($company, DocumentType::Payment, $period);

            $entry = $this->posting->postNew($company, new JournalEntryData(
                entryDate: $data->paymentDate->startOfDay(),
                description: LedgerNarration::limit(sprintf('Payment %s — %s', $number, $supplier->name)),
                lines: $lines,
                reference: $number,
                documentType: DocumentType::JournalVoucher,
                source: SourceDocument::for($payment),
            ), $actor);

            // 14. Save the fully-formed, born-posted header — one INSERT, every attribute explicit (§A7).
            $payment->company_id = $company->getKey();
            $payment->supplier_id = $supplier->getKey();
            $payment->branch_id = $branchId;
            $payment->bank_account_id = $bankAccount->getKey();
            $payment->number = $number;
            $payment->payment_date = $data->paymentDate->startOfDay();
            $payment->currency_code = $company->base_currency_code;
            $payment->exchange_rate = null;
            $payment->amount = $amount;
            $payment->status = PaymentStatus::Posted;
            $payment->reference = $data->reference;
            $payment->notes = $data->notes;
            $payment->journal_entry_id = $entry->getKey();
            $payment->posted_at = now();
            $payment->posted_by_id = $actor?->getKey();
            $payment->created_by_id = $actor?->getKey();
            $payment->save();

            // 15. Save one allocation row per bill.
            foreach ($data->allocations as $allocation) {
                $row = new PaymentAllocation;
                $row->company_id = $company->getKey();
                $row->supplier_payment_id = $payment->getKey();
                $row->bill_id = $allocation->billId;
                $row->amount = $this->assertDecimal($allocation->amount);
                $row->save();
            }

            // 16. Move each locked bill: amount_paid up, amount_due down (preserving bills_amount_due_check),
            //     and status Posted → PartiallyPaid → Paid (AC-2.2, AC-2.3). One save per bill carrying all
            //     three columns together — the immutability trigger permits exactly these.
            foreach ($locked as $bill) {
                $paid = bcadd($bill->amount_paid, $amountFor[(string) $bill->getKey()], Money::SCALE);
                $due = bcsub($bill->total, $paid, Money::SCALE);

                $bill->amount_paid = $paid;
                $bill->amount_due = $due;
                $bill->status = bccomp($due, '0', Money::SCALE) === 0
                    ? BillStatus::Paid
                    : BillStatus::PartiallyPaid;
                $bill->save();
            }

            // 17. Everything committed together; the deferred full-allocation trigger passes (Σ = amount).
            return $payment->refresh();
        });
    }

    /**
     * The supplier, provided it belongs to this company.
     *
     * Ownership only — unlike `BillService::resolveSupplier`, it does NOT call `acceptsNewBills()`: an
     * archived or dormant supplier can still be paid what is already owed (AC-1.10).
     */
    private function resolveSupplier(Company $company, string $supplierId): Supplier
    {
        $supplier = Supplier::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($supplierId)
            ->first();

        if ($supplier === null) {
            throw PaymentCannotBeRecorded::supplierOutsideCompany($supplierId);
        }

        return $supplier;
    }

    /**
     * The bank/cash account, provided it belongs to this company. Type and postability are the posting map's
     * job (§B).
     */
    private function resolveBankAccount(Company $company, string $accountId): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw PaymentCannotBeRecorded::bankAccountOutsideCompany($accountId);
        }

        return $account;
    }

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
     * The named bills, within this company's scope, keyed by id. A bill in another company (or none) simply
     * does not appear — the caller reads that as `billOutsideCompany`.
     *
     * @param  list<string>  $billIds
     * @return array<string, Bill>
     */
    private function loadBills(Company $company, array $billIds): array
    {
        /** @var array<string, Bill> $bills */
        $bills = Bill::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($billIds)
            ->get()
            ->keyBy('id')
            ->all();

        return $bills;
    }

    /**
     * @return numeric-string
     */
    private function assertDecimal(string $value): string
    {
        $trimmed = trim($value);

        if (! is_numeric($trimmed)) {
            throw PaymentCannotBeRecorded::amountNotPositive($value);
        }

        return $trimmed;
    }
}
