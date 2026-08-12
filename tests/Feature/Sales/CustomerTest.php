<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Enums\CustomerStatus;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The customer domain.
 *
 * Milestone 2 of Phase 3. Customers exist; invoices do not until Milestone 4, and two rules here
 * depend on them — a customer with an outstanding balance cannot be archived, one with any invoice
 * cannot be deleted. Both go through `ReceivableBalanceProbe`, so both are testable now by binding a
 * probe that reports a balance. That is the same seam Phase 1 built for `LedgerActivityProbe`, and
 * testing against it is what stops the rules being written and never exercised.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->service = app(CustomerService::class);
});

/**
 * Binds a probe reporting the given balance, so the archive and delete rules can be exercised before
 * invoices exist.
 */
function withReceivables(string $balance, bool $hasInvoice = true): void
{
    app()->bind(ReceivableBalanceProbe::class, fn (): ReceivableBalanceProbe => new class($balance, $hasInvoice) implements ReceivableBalanceProbe
    {
        public function __construct(private string $balance, private bool $hasInvoice) {}

        public function outstandingBalance(Customer $customer): string
        {
            return $this->balance;
        }

        public function hasAnyInvoice(Customer $customer): bool
        {
            return $this->hasInvoice;
        }
    });

    // The service is a singleton holding the probe it was constructed with, so it has to be forgotten
    // and re-resolved for the new binding to reach it.
    app()->forgetInstance(CustomerService::class);
    test()->service = app(CustomerService::class);
}

describe('creating a customer', function (): void {
    it('creates an active customer with generated code', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva Traders'));

        expect($customer->code)->toBe('C-0001')
            ->and($customer->status)->toBe(CustomerStatus::Active)
            ->and($customer->archived_at)->toBeNull()
            ->and($customer->company_id)->toBe($this->company->getKey());
    });

    it('numbers generated codes from the highest existing rather than a count', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'First'));
        $second = $this->service->create($this->company, new CustomerData(name: 'Second'));

        $this->service->delete($second);

        $third = $this->service->create($this->company, new CustomerData(name: 'Third'));

        // Counting rows would reissue C-0002 after the delete and collide with a code already used.
        expect($third->code)->toBe('C-0003');
    });

    it('accepts a supplied code', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));

        expect($customer->code)->toBe('SILVA');
    });

    it('refuses a duplicate code regardless of case', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));

        // "silva" and "SILVA" are one customer to everyone except a naive unique constraint.
        expect(fn () => $this->service->create($this->company, new CustomerData(name: 'Other', code: 'silva')))
            ->toThrow(ResourceConflict::class);
    });

    it('refuses a blank code', function (): void {
        expect(fn () => $this->service->create($this->company, new CustomerData(name: 'X', code: '   ')))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('lets another company in the same workspace reuse a code', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $reused = $this->service->create($second, new CustomerData(name: 'Silva', code: 'SILVA'));

        // Codes are unique per company, not per workspace. Two companies in one workspace that both
        // sell to the same shop keep separate records, because the receivable balance and the credit
        // terms belong to one set of books.
        expect($reused->code)->toBe('SILVA')
            ->and($reused->company_id)->toBe($second->getKey());
    });

    it('generates a code alongside a hand-typed one with an oversized numeric suffix', function (): void {
        // `code` is varchar(32), so `C-` and twenty digits is a legal customer code someone can type.
        // Fed to an unbounded `max(cast(... as integer))` it overflowed, and the failure was not one bad
        // row: generation broke for the whole company, permanently, for every customer after it.
        $this->service->create($this->company, new CustomerData(name: 'Hand typed', code: 'C-99999999999999999999'));

        $generated = $this->service->create($this->company, new CustomerData(name: 'Auto'));

        // The oversized code is outside the generated-code pattern, so it is not counted as a maximum —
        // which is also the right answer rather than a workaround, because it is not a generated code.
        expect($generated->code)->toBe('C-0001');
    });

    it('keeps generating without collision after an oversized code exists', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'Hand typed', code: 'C-'.str_repeat('9', 25)));
        $first = $this->service->create($this->company, new CustomerData(name: 'One'));
        $second = $this->service->create($this->company, new CustomerData(name: 'Two'));
        $third = $this->service->create($this->company, new CustomerData(name: 'Three'));

        expect([$first->code, $second->code, $third->code])->toBe(['C-0001', 'C-0002', 'C-0003'])
            ->and(Customer::query()->forCompany($this->company->getKey())->count())->toBe(4);
    });

    it('still counts a generated code that is merely large but in range', function (): void {
        // The bound is eighteen digits, which a bigint holds. A code inside it must still be counted, or
        // the fix would have quietly turned into "ignore anything inconvenient".
        $this->service->create($this->company, new CustomerData(name: 'Big', code: 'C-000000000000005000'));

        $generated = $this->service->create($this->company, new CustomerData(name: 'Auto'));

        expect($generated->code)->toBe('C-5001');
    });

    it('normalises blank optional fields to null', function (): void {
        $customer = $this->service->create($this->company, CustomerData::fromArray([
            'name' => 'Silva',
            'email' => '',
            'city' => '   ',
        ]));

        // A form posting every field sends empty strings for the blanks. Storing those makes
        // `WHERE email IS NULL` miss customers who have no e-mail.
        expect($customer->email)->toBeNull()
            ->and($customer->city)->toBeNull();
    });

    it('uppercases the country code', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', countryCode: 'lk'));

        expect($customer->country_code)->toBe('LK');
    });
});

describe('validation and business rules', function (): void {
    it('requires a VAT number when the customer is VAT registered', function (): void {
        expect(fn () => $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            isVatRegistered: true,
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses negative payment terms', function (): void {
        expect(fn () => $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            paymentTermsDays: -1,
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a negative credit limit at the database', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        expect(fn () => DB::statement(
            'UPDATE customers SET credit_limit = -1 WHERE id = ?',
            [$customer->getKey()],
        ))->toThrow(QueryException::class);
    });

    it('treats a zero credit limit as different from none', function (): void {
        $none = $this->service->create($this->company, new CustomerData(name: 'Unlimited'));
        $zero = $this->service->create($this->company, new CustomerData(name: 'No credit', creditLimit: '0'));

        // NULL means unlimited; zero means this customer may not be invoiced on credit at all. The two
        // are opposite statements and a nullable column is what keeps them distinguishable.
        expect($none->credit_limit)->toBeNull()
            ->and($zero->credit_limit)->not->toBeNull();
    });

    it('refuses a credit limit that is not a number', function (): void {
        // Reachable from a form field, an import row or an API payload. Unchecked it surfaces as a
        // database type error naming no field; the service names it.
        expect(fn () => $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            creditLimit: '500,000',
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a negative credit limit before the database has to', function (): void {
        expect(fn () => $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            creditLimit: '-1',
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('treats an empty credit limit as no limit', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', creditLimit: '  '));

        expect($customer->credit_limit)->toBeNull();
    });

    it('derives a due date from the payment terms', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', paymentTermsDays: 45));

        expect($customer->dueDateFor(CarbonImmutable::parse('2026-06-15'))->toDateString())
            ->toBe('2026-07-30');
    });

    it('treats zero-day terms as due on receipt rather than missing', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Cash', paymentTermsDays: 0));

        expect($customer->dueDateFor(CarbonImmutable::parse('2026-06-15'))->toDateString())
            ->toBe('2026-06-15');
    });
});

describe('the receivable account', function (): void {
    it('accepts an asset account from the same company', function (): void {
        $receivables = Account::query()->forCompany($this->company->getKey())->where('code', '1130')->firstOrFail();

        $customer = $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            receivableAccountId: $receivables->getKey(),
        ));

        expect($customer->receivable_account_id)->toBe($receivables->getKey());
    });

    it('refuses an account that is not an asset', function (): void {
        $revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

        // The check that matters. Pointing a customer's receivable at revenue would debit income on
        // every invoice, and the trial balance would still tie — so nothing downstream would notice.
        expect(fn () => $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            receivableAccountId: $revenue->getKey(),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a non-postable header account', function (): void {
        $header = Account::query()->forCompany($this->company->getKey())->where('code', '1100')->firstOrFail();

        expect(fn () => $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            receivableAccountId: $header->getKey(),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses an account belonging to another company', function (): void {
        $globex = $this->createWorkspace('globex');
        $foreignAccount = RowLevelSecurity::bypass(
            fn () => Account::query()->withoutGlobalScopes()->where('company_id', $globex['company']->getKey())->first(),
        );

        expect(fn () => $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            receivableAccountId: $foreignAccount?->getKey() ?? (string) Str::uuid7(),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('leaves the account null so the company default applies', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        // Null means "use the company's system AR account". Resolving that at create time would hide
        // which customers were deliberately segmented from those that simply took the default.
        expect($customer->receivable_account_id)->toBeNull();
    });
});

describe('the lifecycle', function (): void {
    it('deactivates without hiding', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        $this->service->deactivate($customer);

        expect($customer->status)->toBe(CustomerStatus::Inactive)
            ->and($customer->acceptsNewInvoices())->toBeFalse()
            ->and($customer->status->isSelectable())->toBeTrue();
    });

    it('archives when nothing is owed', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        $this->service->archive($customer);

        expect($customer->status)->toBe(CustomerStatus::Archived)
            ->and($customer->archived_at)->not->toBeNull()
            ->and($customer->status->isSelectable())->toBeFalse();
    });

    it('refuses to archive a customer who still owes', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        withReceivables('15000.0000');

        // The rule's whole point: an archived customer disappears from the screens someone would use
        // to chase the debt, so archiving one who still owes is how a receivable gets quietly lost.
        expect(fn () => $this->service->archive($customer->fresh()))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('archives a customer whose balance has settled to zero', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        withReceivables('0.0000');

        $this->service->archive($customer->fresh());

        expect($customer->fresh()->status)->toBe(CustomerStatus::Archived);
    });

    it('reactivates an archived customer and clears the timestamp', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $this->service->archive($customer);

        $this->service->reactivate($customer);

        expect($customer->status)->toBe(CustomerStatus::Active)
            ->and($customer->archived_at)->toBeNull();
    });

    it('refuses to deactivate an archived customer', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $this->service->archive($customer);

        expect(fn () => $this->service->deactivate($customer))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('keeps status and archived_at in step at the database', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        // Phase 2 learned this on fiscal_periods: a mass update moved `status` and left the timestamp
        // behind, and the CHECK caught it. The constraint was right and the new code was wrong.
        expect(fn () => DB::statement(
            "UPDATE customers SET status = 'archived' WHERE id = ?",
            [$customer->getKey()],
        ))->toThrow(QueryException::class);
    });
});

describe('deleting', function (): void {
    it('soft-deletes a customer created in error', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Typo'));

        $this->service->delete($customer);

        expect(Customer::query()->find($customer->getKey()))->toBeNull()
            ->and(Customer::query()->withTrashed()->find($customer->getKey()))->not->toBeNull();
    });

    it('refuses to delete a customer that has been invoiced', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        withReceivables('0.0000', hasInvoice: true);

        // Owing nothing is not the test. An invoice is a statutory record naming this customer, so the
        // record has to outlive the relationship even when the balance is settled.
        expect(fn () => $this->service->delete($customer->fresh()))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('restores a soft-deleted customer whose code is still free', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($customer);

        $restored = $this->service->restore($customer);

        expect($restored->trashed())->toBeFalse()
            ->and(Customer::query()->find($customer->getKey()))->not->toBeNull()
            ->and($restored->code)->toBe('SILVA');
    });

    it('refuses to restore when the code has since been reused', function (): void {
        $original = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($original);
        $this->service->create($this->company, new CustomerData(name: 'Silva Traders', code: 'SILVA'));

        // Left to the database this is a UniqueConstraintViolationException — a 500 carrying a constraint
        // name, telling the user nothing they can act on. It has to arrive as the conflict it is.
        expect(fn () => $this->service->restore($original))
            ->toThrow(ResourceConflict::class);
    });

    it('names the code and the remedy when a restore conflicts', function (): void {
        $original = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($original);
        $this->service->create($this->company, new CustomerData(name: 'Silva Traders', code: 'SILVA'));

        try {
            $this->service->restore($original);
            expect()->fail('the restore should have conflicted');
        } catch (ResourceConflict $conflict) {
            // The caller did not choose this code, they chose a customer — so "already exists" is true
            // and useless. The message has to say which code and what to do.
            expect($conflict->problemCode())->toBe('customer-code-taken-on-restore')
                ->and($conflict->getMessage())->toContain('SILVA')
                ->and($conflict->getMessage())->toContain('Change the code');
        }
    });

    it('leaves the customer deleted when a restore is refused', function (): void {
        $original = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($original);
        $this->service->create($this->company, new CustomerData(name: 'Silva Traders', code: 'SILVA'));

        try {
            $this->service->restore($original);
        } catch (ResourceConflict) {
            // Expected.
        }

        // A refused restore must change nothing. The check and the restore share a transaction, so a
        // half-restored customer is not reachable.
        expect(Customer::query()->find($original->getKey()))->toBeNull()
            ->and(Customer::query()->withTrashed()->find($original->getKey())->trashed())->toBeTrue();
    });

    it('refuses to restore a customer that was never deleted', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        expect(fn () => $this->service->restore($customer))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('frees the code for reuse once soft-deleted', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($customer);

        // The unique index excludes soft-deleted rows, so a code typed by mistake is not burned for ever.
        $replacement = $this->service->create($this->company, new CustomerData(name: 'Silva Traders', code: 'SILVA'));

        expect($replacement->code)->toBe('SILVA');
    });
});

describe('updating', function (): void {
    it('changes details', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        $this->service->update($customer, [
            'name' => 'Silva Traders (Pvt) Ltd',
            'payment_terms_days' => 60,
            'credit_limit' => '500000.0000',
        ]);

        expect($customer->name)->toBe('Silva Traders (Pvt) Ltd')
            ->and($customer->payment_terms_days)->toBe(60);
    });

    it('changes the code while nothing has been invoiced', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'OLD'));

        $this->service->update($customer, ['code' => 'NEW']);

        expect($customer->code)->toBe('NEW');
    });

    it('refuses to change the code once the customer has been invoiced', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'OLD'));

        withReceivables('0.0000', hasInvoice: true);

        // The code appears on documents the customer already holds. Changing it would leave two
        // identifiers for one account.
        expect(fn () => $this->service->update($customer->fresh(), ['code' => 'NEW']))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a code already used by another customer', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'A', code: 'TAKEN'));
        $second = $this->service->create($this->company, new CustomerData(name: 'B', code: 'FREE'));

        expect(fn () => $this->service->update($second, ['code' => 'taken']))
            ->toThrow(ResourceConflict::class);
    });

    it('permits keeping its own code', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', code: 'SILVA'));

        $this->service->update($customer, ['name' => 'Renamed', 'code' => 'SILVA']);

        expect($customer->name)->toBe('Renamed');
    });
});

describe('updating — attribute-array clear-vs-omit semantics (I3)', function (): void {
    it('clears branch_id when the key is present with null', function (): void {
        $branch = Branch::factory()->for($this->company)->create();
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', branchId: $branch->getKey()));

        $this->service->update($customer, ['branch_id' => null]);

        expect($customer->branch_id)->toBeNull();
    });

    it('leaves branch_id untouched when the key is omitted', function (): void {
        $branch = Branch::factory()->for($this->company)->create();
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', branchId: $branch->getKey()));

        $this->service->update($customer, ['name' => 'Silva Renamed']);

        expect($customer->branch_id)->toBe($branch->getKey());
    });

    it('clears receivable_account_id when the key is present with null', function (): void {
        $receivables = Account::query()->forCompany($this->company->getKey())->where('code', '1130')->firstOrFail();
        $customer = $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            receivableAccountId: $receivables->getKey(),
        ));

        $this->service->update($customer, ['receivable_account_id' => null]);

        expect($customer->receivable_account_id)->toBeNull();
    });

    it('leaves receivable_account_id untouched when the key is omitted', function (): void {
        $receivables = Account::query()->forCompany($this->company->getKey())->where('code', '1130')->firstOrFail();
        $customer = $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            receivableAccountId: $receivables->getKey(),
        ));

        $this->service->update($customer, ['name' => 'Silva Renamed']);

        expect($customer->receivable_account_id)->toBe($receivables->getKey());
    });

    it('clears credit_limit when the key is present with null', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', creditLimit: '500000.0000'));

        $this->service->update($customer, ['credit_limit' => null]);

        expect($customer->credit_limit)->toBeNull();
    });

    it('leaves credit_limit untouched when the key is omitted', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva', creditLimit: '500000.0000'));

        $this->service->update($customer, ['name' => 'Silva Renamed']);

        expect($customer->credit_limit)->not->toBeNull()
            ->and((string) $customer->credit_limit)->toBe('500000.0000');
    });
});

describe('updating — the VAT cross-rule on effective values (I3)', function (): void {
    it('refuses clearing the VAT number while the customer stays registered', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            isVatRegistered: true,
            vatRegistrationNumber: '123456789',
        ));

        // `is_vat_registered` is not in this update, so its effective value is the current `true` —
        // the cross-rule has to evaluate against that, not against the attributes actually supplied.
        expect(fn () => $this->service->update($customer, ['vat_registration_number' => null]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('permits clearing the VAT number in the same update that unregisters the customer', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            isVatRegistered: true,
            vatRegistrationNumber: '123456789',
        ));

        $this->service->update($customer, ['is_vat_registered' => false, 'vat_registration_number' => null]);

        expect($customer->is_vat_registered)->toBeFalse()
            ->and($customer->vat_registration_number)->toBeNull();
    });

    it('refuses registering a customer that has no VAT number on file', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        // `vat_registration_number` is not in this update, so its effective value is the current
        // `null` — the cross-rule still has to catch it.
        expect(fn () => $this->service->update($customer, ['is_vat_registered' => true]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('permits registering a customer while supplying the number in the same update', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        $this->service->update($customer, ['is_vat_registered' => true, 'vat_registration_number' => '999888777']);

        expect($customer->is_vat_registered)->toBeTrue()
            ->and($customer->vat_registration_number)->toBe('999888777');
    });
});

describe('updating — I4 code-uniqueness race', function (): void {
    it('translates a code-uniqueness collision at the database into a conflict, not a raw query exception', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'Existing', code: 'RACE'));

        // Built directly rather than through the service, to bypass `assertCodeAvailable()` — the
        // read-then-write pre-check — the same way a second, concurrent request would: both requests
        // read "free" before either writes, and only one insert can win. This exercises the exact
        // catch this service's private `save()` exists for, deterministically rather than by racing
        // real database connections.
        $racer = new Customer;
        $racer->company_id = $this->company->getKey();
        $racer->code = 'RACE';
        $racer->name = 'Racer';

        $save = new ReflectionMethod(CustomerService::class, 'save');
        $save->setAccessible(true);

        try {
            $save->invoke($this->service, $racer);
            expect()->fail('the race should have conflicted');
        } catch (ResourceConflict $conflict) {
            expect($conflict->problemCode())->toBe('duplicate-resource');
        }
    });

    it('never lets the raw QueryException escape the code-uniqueness race', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'Existing', code: 'RACE2'));

        $racer = new Customer;
        $racer->company_id = $this->company->getKey();
        $racer->code = 'RACE2';
        $racer->name = 'Racer';

        $save = new ReflectionMethod(CustomerService::class, 'save');
        $save->setAccessible(true);

        expect(fn () => $save->invoke($this->service, $racer))
            ->not->toThrow(QueryException::class);
    });
});

describe('updating — M8, validate before assign', function (): void {
    it('leaves the in-memory model unchanged when the update is refused', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(
            name: 'Silva',
            paymentTermsDays: 30,
        ));

        $originalName = $customer->name;
        $originalPaymentTermsDays = $customer->payment_terms_days;
        $originalCode = $customer->code;

        expect(fn () => $this->service->update($customer, [
            'name' => 'Should Not Stick',
            'payment_terms_days' => -5,
        ]))->toThrow(BusinessRuleViolation::class);

        expect($customer->name)->toBe($originalName)
            ->and($customer->payment_terms_days)->toBe($originalPaymentTermsDays)
            ->and($customer->code)->toBe($originalCode)
            ->and($customer->isDirty())->toBeFalse();
    });
});

describe('the audit trail', function (): void {
    it('records a credit limit change', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));

        $this->service->update($customer, ['credit_limit' => '1000000.0000']);

        $entries = AuditLog::query()->where('auditable_type', Customer::MORPH_ALIAS)->get();

        // A limit raised days before a large sale is a question an auditor asks, and the answer has to
        // be in the trail rather than in someone's memory.
        expect($entries)->not->toBeEmpty()
            ->and($entries->pluck('new_values')->toJson())->toContain('credit_limit');
    });

    it('records the archive transition', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $this->service->archive($customer);

        $statuses = AuditLog::query()
            ->where('auditable_type', Customer::MORPH_ALIAS)
            ->get()
            ->pluck('new_values')
            ->toJson();

        expect($statuses)->toContain('archived');
    });
});

describe('tenant isolation enforced by the database', function (): void {
    it('hides another workspace’s customers from raw SQL', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'Acme Customer'));

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // Raw SQL, bypassing Eloquent's global scope, so the policy is the only thing that can hide
        // the row. An Eloquent assertion here would pass with the policies switched off.
        expect(DB::table('customers')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('customers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('keeps a restore inside the tenant', function (): void {
        $customer = $this->service->create($this->company, new CustomerData(name: 'Acme Customer', code: 'SHARED'));
        $this->service->delete($customer);

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // The restore check queries by code within the company. Under another tenant the row is invisible
        // to the policy, so a soft-deleted customer cannot be resurrected from outside its workspace —
        // asserted because the check is a new access path, and a new access path is where isolation gets
        // missed.
        expect(DB::table('customers')->whereNotNull('deleted_at')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('customers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('refuses a cross-tenant write', function (): void {
        $this->service->create($this->company, new CustomerData(name: 'Acme Customer'));
        $acmeTenantId = $this->acme['tenant']->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(fn () => DB::table('customers')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $acmeTenantId,
            'company_id' => $this->company->getKey(),
            'code' => 'SNEAK',
            'name' => 'Planted',
            'status' => 'active',
            'payment_terms_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('customers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});

describe('authorization', function (): void {
    it('lets an accountant manage customers', function (): void {
        $accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'acct@acme.test']);
        app(MembershipService::class)
            ->grant($this->company, $accountant, $this->owner);

        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $fresh = RowLevelSecurity::bypass(fn () => $accountant->fresh());

        expect($fresh->can('view', $customer))->toBeTrue()
            ->and($fresh->can('update', $customer))->toBeTrue();
    });

    it('refuses a viewer any write', function (): void {
        $viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer', ['email' => 'view@acme.test']);
        app(MembershipService::class)
            ->grant($this->company, $viewer, $this->owner);

        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $fresh = RowLevelSecurity::bypass(fn () => $viewer->fresh());

        expect($fresh->can('view', $customer))->toBeTrue()
            ->and($fresh->can('update', $customer))->toBeFalse()
            ->and($fresh->can('create', Customer::class))->toBeFalse();
    });

    it('lets an accountant restore but refuses a viewer', function (): void {
        $memberships = app(MembershipService::class);

        $accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'acct2@acme.test']);
        $viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer', ['email' => 'view2@acme.test']);
        $memberships->grant($this->company, $accountant, $this->owner);
        $memberships->grant($this->company, $viewer, $this->owner);

        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $this->service->delete($customer);

        // The policy method existed before the service did. Now that restoring is a real operation, the
        // capability it needs has to be asserted rather than assumed.
        expect(RowLevelSecurity::bypass(fn () => $accountant->fresh())->can('restore', $customer))->toBeTrue()
            ->and(RowLevelSecurity::bypass(fn () => $viewer->fresh())->can('restore', $customer))->toBeFalse();
    });

    it('refuses restore to a user with the capability but no membership of the company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'out2@acme.test']);

        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $this->service->delete($customer);

        // Permission and membership are different questions and the policy asks both.
        expect(RowLevelSecurity::bypass(fn () => $outsider->fresh())->can('restore', $customer))->toBeFalse();
    });

    it('refuses a member of another company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'out@acme.test']);
        $customer = $this->service->create($this->company, new CustomerData(name: 'Silva'));
        $fresh = RowLevelSecurity::bypass(fn () => $outsider->fresh());

        // Permission and membership are different questions and the policy asks both. This user has
        // `sales.customers.manage` and no membership of the company.
        expect($fresh->can('update', $customer))->toBeFalse();
    });
});
