<?php

declare(strict_types=1);

use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Enums\SupplierStatus;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The supplier domain — Stage 4 of Wave 6 (ADR 0018 §C, §G).
 *
 * The payable-side mirror of `tests/Feature/Sales/CustomerTest.php`. Suppliers exist; bills do not
 * until Wave 7, and three rules here depend on them — a supplier with an outstanding balance cannot be
 * archived, one named by any bill cannot be deleted, and one named by any bill cannot be recoded. All
 * three go through `PayableBalanceProbe`, so all three are testable now by binding a probe that reports
 * a balance. Testing against the seam is what stops the rules being written and never exercised.
 *
 * The deferred-field cases from the customer original (credit_limit, the receivable/AP account) are
 * omitted: those columns do not exist this slice (Gate-1 decision 4).
 *
 * RED expectation before Stage 4 lands: `SupplierService` / `SupplierData` do not exist.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    $this->service = app(SupplierService::class);
});

/**
 * Binds a probe reporting the given balance, so the archive, delete and code-lock rules can be exercised
 * before bills exist. The mirror of `withReceivables()`; named distinctly because Pest helpers are global.
 */
function withPayables(string $balance, bool $hasBill = true): void
{
    app()->bind(PayableBalanceProbe::class, fn (): PayableBalanceProbe => new class($balance, $hasBill) implements PayableBalanceProbe
    {
        public function __construct(private string $balance, private bool $hasBill) {}

        public function outstandingBalance(Supplier $supplier): string
        {
            return $this->balance;
        }

        public function hasAnyBill(Supplier $supplier): bool
        {
            return $this->hasBill;
        }
    });

    // The service is a singleton holding the probe it was constructed with, so it has to be forgotten and
    // re-resolved for the new binding to reach it.
    app()->forgetInstance(SupplierService::class);
    test()->service = app(SupplierService::class);
}

describe('creating a supplier', function (): void {
    it('creates an active supplier with a generated S- code', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva Suppliers'));

        expect($supplier->code)->toBe('S-0001')
            ->and($supplier->status)->toBe(SupplierStatus::Active)
            ->and($supplier->archived_at)->toBeNull()
            ->and($supplier->company_id)->toBe($this->company->getKey());
    });

    it('numbers generated codes from the highest existing rather than a count', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'First'));
        $second = $this->service->create($this->company, new SupplierData(name: 'Second'));

        $this->service->delete($second);

        $third = $this->service->create($this->company, new SupplierData(name: 'Third'));

        // Counting rows would reissue S-0002 after the delete and collide with a code already used.
        expect($third->code)->toBe('S-0003');
    });

    it('accepts a supplied code', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));

        expect($supplier->code)->toBe('SILVA');
    });

    it('refuses a duplicate code regardless of case', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));

        expect(fn () => $this->service->create($this->company, new SupplierData(name: 'Other', code: 'silva')))
            ->toThrow(ResourceConflict::class);
    });

    it('refuses a blank code with a named problem', function (): void {
        $exception = catchPlatformException(
            fn () => $this->service->create($this->company, new SupplierData(name: 'X', code: '   ')),
        );

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('supplier-code-blank');
    });

    it('lets another company in the same workspace reuse a code', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $reused = $this->service->create($second, new SupplierData(name: 'Silva', code: 'SILVA'));

        // Codes are unique per company, not per workspace: two companies that both buy from the same shop
        // keep separate supplier records, because the payable balance and the terms belong to one set of books.
        expect($reused->code)->toBe('SILVA')
            ->and($reused->company_id)->toBe($second->getKey());
    });

    it('generates a code alongside a hand-typed one with an oversized numeric suffix', function (): void {
        // `code` is varchar(32), so `S-` and twenty digits is a legal supplier code someone can type. Fed to
        // an unbounded `max(cast(...))` it overflowed, breaking generation for the whole company permanently.
        $this->service->create($this->company, new SupplierData(name: 'Hand typed', code: 'S-99999999999999999999'));

        $generated = $this->service->create($this->company, new SupplierData(name: 'Auto'));

        // The oversized code is outside the generated-code pattern, so it is not counted as a maximum.
        expect($generated->code)->toBe('S-0001');
    });

    it('still counts a generated code that is merely large but in range', function (): void {
        // The bound is eighteen digits, which a bigint holds. A code inside it must still be counted, or the
        // fix would have quietly turned into "ignore anything inconvenient".
        $this->service->create($this->company, new SupplierData(name: 'Big', code: 'S-000000000000005000'));

        $generated = $this->service->create($this->company, new SupplierData(name: 'Auto'));

        expect($generated->code)->toBe('S-5001');
    });

    it('normalises blank optional fields to null', function (): void {
        $supplier = $this->service->create($this->company, SupplierData::fromArray([
            'name' => 'Silva',
            'email' => '',
            'city' => '   ',
        ]));

        // A form posting every field sends empty strings for the blanks. Storing those makes
        // `WHERE email IS NULL` miss suppliers who have no e-mail.
        expect($supplier->email)->toBeNull()
            ->and($supplier->city)->toBeNull();
    });

    it('uppercases the country code', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', countryCode: 'lk'));

        expect($supplier->country_code)->toBe('LK');
    });

    it('keeps the tax identification number it is given', function (): void {
        // Retained for Wave 8 supplier-WHT / compliance (Gate-1 decision 4).
        $supplier = $this->service->create($this->company, new SupplierData(
            name: 'Silva',
            taxIdentificationNumber: '123456789V',
        ));

        expect($supplier->tax_identification_number)->toBe('123456789V');
    });
});

describe('validation and business rules', function (): void {
    it('requires a VAT number when the supplier is VAT registered', function (): void {
        $exception = catchPlatformException(fn () => $this->service->create($this->company, new SupplierData(
            name: 'Silva',
            isVatRegistered: true,
        )));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('vat-registration-number-required');
    });

    it('refuses negative payment terms', function (): void {
        $exception = catchPlatformException(fn () => $this->service->create($this->company, new SupplierData(
            name: 'Silva',
            paymentTermsDays: -1,
        )));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('negative-payment-terms');
    });

    it('refuses a branch belonging to another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $foreignBranch = Branch::factory()->for($second)->create();

        $exception = catchPlatformException(fn () => $this->service->create($this->company, new SupplierData(
            name: 'Silva',
            branchId: $foreignBranch->getKey(),
        )));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('branch-outside-company');
    });

    it('derives a due date from the payment terms', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', paymentTermsDays: 45));

        expect($supplier->dueDateFor(CarbonImmutable::parse('2026-06-15'))->toDateString())
            ->toBe('2026-07-30');
    });

    it('treats zero-day terms as due on receipt rather than missing', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Cash', paymentTermsDays: 0));

        expect($supplier->dueDateFor(CarbonImmutable::parse('2026-06-15'))->toDateString())
            ->toBe('2026-06-15');
    });
});

describe('the outstanding balance', function (): void {
    it('delegates to the probe, which is zero while the seam is dormant', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        // Exposed on the service so callers ask it rather than reaching for the probe, which is an
        // implementation detail Wave 7 changes.
        expect($this->service->outstandingBalance($supplier))->toBe('0.0000');
    });
});

describe('the lifecycle', function (): void {
    it('deactivates without hiding', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        $this->service->deactivate($supplier);

        expect($supplier->status)->toBe(SupplierStatus::Inactive)
            ->and($supplier->acceptsNewBills())->toBeFalse()
            ->and($supplier->status->isSelectable())->toBeTrue();
    });

    it('archives when nothing is owed', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        $this->service->archive($supplier);

        expect($supplier->status)->toBe(SupplierStatus::Archived)
            ->and($supplier->archived_at)->not->toBeNull()
            ->and($supplier->status->isSelectable())->toBeFalse();
    });

    it('refuses to archive a supplier the company still owes', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        withPayables('15000.0000');

        // The rule's whole point: an archived supplier disappears from the screens someone would use to
        // pay it, so archiving one still owed is how a payable gets quietly lost.
        $exception = catchPlatformException(fn () => $this->service->archive($supplier->fresh()));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('supplier-has-outstanding-balance');
    });

    it('archives a supplier whose balance has settled to zero', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        withPayables('0.0000');

        $this->service->archive($supplier->fresh());

        expect($supplier->fresh()->status)->toBe(SupplierStatus::Archived);
    });

    it('reactivates an archived supplier and clears the timestamp', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));
        $this->service->archive($supplier);

        $this->service->reactivate($supplier);

        expect($supplier->status)->toBe(SupplierStatus::Active)
            ->and($supplier->archived_at)->toBeNull();
    });

    it('refuses to deactivate an archived supplier', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));
        $this->service->archive($supplier);

        $exception = catchPlatformException(fn () => $this->service->deactivate($supplier));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('supplier-archived');
    });

    it('keeps status and archived_at in step at the database', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        // Phase 2 learned this on fiscal_periods: a mass update moved `status` and left the timestamp
        // behind, and the CHECK caught it. The constraint was right and the new code was wrong.
        expect(fn () => DB::statement(
            "UPDATE suppliers SET status = 'archived' WHERE id = ?",
            [$supplier->getKey()],
        ))->toThrow(QueryException::class);
    });
});

describe('deleting', function (): void {
    it('soft-deletes a supplier created in error', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Typo'));

        $this->service->delete($supplier);

        expect(Supplier::query()->find($supplier->getKey()))->toBeNull()
            ->and(Supplier::query()->withTrashed()->find($supplier->getKey()))->not->toBeNull();
    });

    it('refuses to delete a supplier that has been billed', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        withPayables('0.0000', hasBill: true);

        // Owing nothing is not the test. A bill is a statutory record naming this supplier, so the record
        // has to outlive the relationship even when the balance is settled.
        $exception = catchPlatformException(fn () => $this->service->delete($supplier->fresh()));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('supplier-has-bills');
    });

    it('restores a soft-deleted supplier whose code is still free', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($supplier);

        $restored = $this->service->restore($supplier);

        expect($restored->trashed())->toBeFalse()
            ->and(Supplier::query()->find($supplier->getKey()))->not->toBeNull()
            ->and($restored->code)->toBe('SILVA');
    });

    it('refuses to restore when the code has since been reused, naming the remedy', function (): void {
        $original = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($original);
        $this->service->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));

        try {
            $this->service->restore($original);
            expect()->fail('the restore should have conflicted');
        } catch (ResourceConflict $conflict) {
            // The caller did not choose this code, they chose a supplier — so "already exists" is true and
            // useless. The message has to say which code and what to do.
            expect($conflict->problemCode())->toBe('supplier-code-taken-on-restore')
                ->and($conflict->getMessage())->toContain('SILVA')
                ->and($conflict->getMessage())->toContain('Change the code');
        }
    });

    it('leaves the supplier deleted when a restore is refused', function (): void {
        $original = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($original);
        $this->service->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));

        try {
            $this->service->restore($original);
        } catch (ResourceConflict) {
            // Expected.
        }

        // A refused restore must change nothing. The check and the restore share a transaction, so a
        // half-restored supplier is not reachable.
        expect(Supplier::query()->find($original->getKey()))->toBeNull()
            ->and(Supplier::query()->withTrashed()->find($original->getKey())->trashed())->toBeTrue();
    });

    it('refuses to restore a supplier that was never deleted', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        $exception = catchPlatformException(fn () => $this->service->restore($supplier));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('supplier-not-deleted');
    });

    it('frees the code for reuse once soft-deleted', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));
        $this->service->delete($supplier);

        $replacement = $this->service->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));

        expect($replacement->code)->toBe('SILVA');
    });
});

describe('updating', function (): void {
    it('changes details', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        $this->service->update($supplier, [
            'name' => 'Silva Suppliers (Pvt) Ltd',
            'payment_terms_days' => 60,
        ]);

        expect($supplier->name)->toBe('Silva Suppliers (Pvt) Ltd')
            ->and($supplier->payment_terms_days)->toBe(60);
    });

    it('changes the code while nothing has been billed', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'OLD'));

        $this->service->update($supplier, ['code' => 'NEW']);

        expect($supplier->code)->toBe('NEW');
    });

    it('refuses to change the code once the supplier has been billed', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'OLD'));

        withPayables('0.0000', hasBill: true);

        // The code appears on documents the supplier already has. Changing it would leave two identifiers
        // for one account.
        $exception = catchPlatformException(fn () => $this->service->update($supplier->fresh(), ['code' => 'NEW']));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('supplier-code-locked');
    });

    it('refuses a code already used by another supplier', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'A', code: 'TAKEN'));
        $second = $this->service->create($this->company, new SupplierData(name: 'B', code: 'FREE'));

        expect(fn () => $this->service->update($second, ['code' => 'taken']))
            ->toThrow(ResourceConflict::class);
    });

    it('permits keeping its own code', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', code: 'SILVA'));

        $this->service->update($supplier, ['name' => 'Renamed', 'code' => 'SILVA']);

        expect($supplier->name)->toBe('Renamed');
    });
});

describe('updating — attribute-array clear-vs-omit semantics', function (): void {
    it('clears branch_id when the key is present with null', function (): void {
        $branch = Branch::factory()->for($this->company)->create();
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', branchId: $branch->getKey()));

        $this->service->update($supplier, ['branch_id' => null]);

        expect($supplier->branch_id)->toBeNull();
    });

    it('leaves branch_id untouched when the key is omitted', function (): void {
        $branch = Branch::factory()->for($this->company)->create();
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva', branchId: $branch->getKey()));

        $this->service->update($supplier, ['name' => 'Silva Renamed']);

        expect($supplier->branch_id)->toBe($branch->getKey());
    });
});

describe('updating — the VAT cross-rule on effective values', function (): void {
    it('refuses clearing the VAT number while the supplier stays registered', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(
            name: 'Silva',
            isVatRegistered: true,
            vatRegistrationNumber: '123456789',
        ));

        // `is_vat_registered` is not in this update, so its effective value is the current `true` — the
        // cross-rule has to evaluate against that, not against the attributes actually supplied.
        expect(fn () => $this->service->update($supplier, ['vat_registration_number' => null]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('permits clearing the VAT number in the same update that unregisters the supplier', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(
            name: 'Silva',
            isVatRegistered: true,
            vatRegistrationNumber: '123456789',
        ));

        $this->service->update($supplier, ['is_vat_registered' => false, 'vat_registration_number' => null]);

        expect($supplier->is_vat_registered)->toBeFalse()
            ->and($supplier->vat_registration_number)->toBeNull();
    });

    it('refuses registering a supplier that has no VAT number on file', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        // `vat_registration_number` is not in this update, so its effective value is the current `null`.
        expect(fn () => $this->service->update($supplier, ['is_vat_registered' => true]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('permits registering a supplier while supplying the number in the same update', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        $this->service->update($supplier, ['is_vat_registered' => true, 'vat_registration_number' => '999888777']);

        expect($supplier->is_vat_registered)->toBeTrue()
            ->and($supplier->vat_registration_number)->toBe('999888777');
    });
});

describe('updating — the code-uniqueness race', function (): void {
    it('translates a code-uniqueness collision at the database into a conflict, not a raw query exception', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'Existing', code: 'RACE'));

        // Built directly rather than through the service, to bypass `assertCodeAvailable()` — the
        // read-then-write pre-check — the same way a second, concurrent request would: both read "free"
        // before either writes, and only one insert can win. This exercises the exact catch the service's
        // private `save()` exists for, deterministically rather than by racing real connections.
        $racer = new Supplier;
        $racer->company_id = $this->company->getKey();
        $racer->code = 'RACE';
        $racer->name = 'Racer';

        $save = new ReflectionMethod(SupplierService::class, 'save');
        $save->setAccessible(true);

        try {
            $save->invoke($this->service, $racer);
            expect()->fail('the race should have conflicted');
        } catch (ResourceConflict $conflict) {
            expect($conflict->problemCode())->toBe('duplicate-resource');
        }
    });

    it('never lets the raw QueryException escape the code-uniqueness race', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'Existing', code: 'RACE2'));

        $racer = new Supplier;
        $racer->company_id = $this->company->getKey();
        $racer->code = 'RACE2';
        $racer->name = 'Racer';

        $save = new ReflectionMethod(SupplierService::class, 'save');
        $save->setAccessible(true);

        expect(fn () => $save->invoke($this->service, $racer))
            ->not->toThrow(QueryException::class);
    });
});

describe('updating — validate before assign', function (): void {
    it('leaves the in-memory model unchanged when the update is refused', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(
            name: 'Silva',
            paymentTermsDays: 30,
        ));

        $originalName = $supplier->name;
        $originalPaymentTermsDays = $supplier->payment_terms_days;
        $originalCode = $supplier->code;

        expect(fn () => $this->service->update($supplier, [
            'name' => 'Should Not Stick',
            'payment_terms_days' => -5,
        ]))->toThrow(BusinessRuleViolation::class);

        expect($supplier->name)->toBe($originalName)
            ->and($supplier->payment_terms_days)->toBe($originalPaymentTermsDays)
            ->and($supplier->code)->toBe($originalCode)
            ->and($supplier->isDirty())->toBeFalse();
    });
});

describe('the audit trail', function (): void {
    it('records a tax identification number change', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));

        $this->service->update($supplier, ['tax_identification_number' => '123456789V']);

        $entries = AuditLog::query()->where('auditable_type', Supplier::MORPH_ALIAS)->get();

        // The Gate-2 divergence, end to end: the TIN was retained for WHT/compliance, so a changed TIN is
        // exactly the sort of thing an auditor asks about and must be in the trail (ADR 0018 §C2).
        expect($entries)->not->toBeEmpty()
            ->and($entries->pluck('new_values')->toJson())->toContain('tax_identification_number');
    });

    it('records the archive transition', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Silva'));
        $this->service->archive($supplier);

        $statuses = AuditLog::query()
            ->where('auditable_type', Supplier::MORPH_ALIAS)
            ->get()
            ->pluck('new_values')
            ->toJson();

        expect($statuses)->toContain('archived');
    });
});

describe('tenant isolation enforced by the database', function (): void {
    it('hides another workspace’s suppliers from raw SQL', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'Acme Supplier'));

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // Raw SQL, bypassing Eloquent's global scope, so the policy is the only thing that can hide the row.
        expect(DB::table('suppliers')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('suppliers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('keeps a restore inside the tenant', function (): void {
        $supplier = $this->service->create($this->company, new SupplierData(name: 'Acme Supplier', code: 'SHARED'));
        $this->service->delete($supplier);

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // The restore check queries by code within the company. Under another tenant the row is invisible
        // to the policy, so a soft-deleted supplier cannot be resurrected from outside its workspace.
        expect(DB::table('suppliers')->whereNotNull('deleted_at')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('suppliers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('refuses a cross-tenant write', function (): void {
        $this->service->create($this->company, new SupplierData(name: 'Acme Supplier'));
        $acmeTenantId = $this->acme['tenant']->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(fn () => DB::table('suppliers')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
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
        fn () => ! RowLevelSecurity::isEnforced('suppliers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
