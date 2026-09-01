<?php

declare(strict_types=1);

use Asids\Core\Purchasing\Domain\Enums\SupplierStatus;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The `Supplier` model and `SupplierStatus` enum — Stage 2 of Wave 6 (ADR 0018 §C1-C2, §F).
 *
 * The payable-side mirror of the `Customer` model. Where the customer domain made a decision the
 * supplier domain makes the identical one; the deliberate divergences this file pins are the payable
 * vocabulary (`acceptsNewBills`), the dropped deferred columns, and the one Gate-2 departure —
 * `auditOnly()` includes `tax_identification_number` (Gate 2 decision, ADR 0018 §C2 / §H item 5).
 *
 * RED expectation before Stage 2 lands: the `Asids\Core\Purchasing\Domain\…` classes do not exist.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
});

describe('the SupplierStatus enum', function (): void {
    it('accepts new bills only while active', function (): void {
        // The payable-side rename of `CustomerStatus::acceptsNewInvoices()` (AC12, ADR 0018 §C1).
        expect(SupplierStatus::Active->acceptsNewBills())->toBeTrue()
            ->and(SupplierStatus::Inactive->acceptsNewBills())->toBeFalse()
            ->and(SupplierStatus::Archived->acceptsNewBills())->toBeFalse();
    });

    it('is selectable unless archived', function (): void {
        expect(SupplierStatus::Active->isSelectable())->toBeTrue()
            ->and(SupplierStatus::Inactive->isSelectable())->toBeTrue()
            ->and(SupplierStatus::Archived->isSelectable())->toBeFalse();
    });

    it('labels each case', function (): void {
        expect(SupplierStatus::Active->label())->toBe('Active')
            ->and(SupplierStatus::Inactive->label())->toBe('Inactive')
            ->and(SupplierStatus::Archived->label())->toBe('Archived');
    });

    it('backs its cases with the customer-mirrored string values', function (): void {
        expect(SupplierStatus::Active->value)->toBe('active')
            ->and(SupplierStatus::Inactive->value)->toBe('inactive')
            ->and(SupplierStatus::Archived->value)->toBe('archived');
    });
});

describe('casts', function (): void {
    it('casts status, the VAT flag, terms and the archive timestamp', function (): void {
        $supplier = Supplier::factory()->archived()->create(['company_id' => $this->company->getKey()]);
        $fresh = $supplier->fresh();

        expect($fresh->status)->toBeInstanceOf(SupplierStatus::class)
            ->and($fresh->status)->toBe(SupplierStatus::Archived)
            ->and($fresh->is_vat_registered)->toBeBool()
            ->and($fresh->payment_terms_days)->toBeInt()
            ->and($fresh->archived_at)->toBeInstanceOf(CarbonImmutable::class);
    });
});

describe('the fillable contract', function (): void {
    it('includes the tax identification number', function (): void {
        expect((new Supplier)->getFillable())->toContain('tax_identification_number');
    });

    it('excludes the deferred and lifecycle-guarded fields', function (): void {
        $fillable = (new Supplier)->getFillable();

        // Deferred to Wave 7, and status transitions go through named service methods, never mass-assignment.
        expect($fillable)->not->toContain('credit_limit')
            ->and($fillable)->not->toContain('receivable_account_id')
            ->and($fillable)->not->toContain('status')
            ->and($fillable)->not->toContain('archived_at');
    });
});

describe('predicates and derived values', function (): void {
    it('delegates acceptsNewBills to its status', function (): void {
        $active = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
        $inactive = Supplier::factory()->inactive()->create(['company_id' => $this->company->getKey()]);

        expect($active->acceptsNewBills())->toBeTrue()
            ->and($inactive->acceptsNewBills())->toBeFalse();
    });

    it('knows when it is archived', function (): void {
        $active = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
        $archived = Supplier::factory()->archived()->create(['company_id' => $this->company->getKey()]);

        expect($active->isArchived())->toBeFalse()
            ->and($archived->isArchived())->toBeTrue();
    });

    it('derives a due date from the payment terms', function (): void {
        // The terms this company *receives* from the supplier; same arithmetic as the customer side.
        $supplier = Supplier::factory()->onTerms(45)->create(['company_id' => $this->company->getKey()]);

        expect($supplier->dueDateFor(CarbonImmutable::parse('2026-06-15'))->toDateString())
            ->toBe('2026-07-30');
    });

    it('treats zero-day terms as due on receipt', function (): void {
        $supplier = Supplier::factory()->onTerms(0)->create(['company_id' => $this->company->getKey()]);

        expect($supplier->dueDateFor(CarbonImmutable::parse('2026-06-15'))->toDateString())
            ->toBe('2026-06-15');
    });
});

describe('scopes', function (): void {
    it('offers active and inactive suppliers to a picker but hides the archived', function (): void {
        Supplier::factory()->create(['company_id' => $this->company->getKey()]);
        Supplier::factory()->inactive()->create(['company_id' => $this->company->getKey()]);
        Supplier::factory()->archived()->create(['company_id' => $this->company->getKey()]);

        expect(Supplier::query()->forCompany($this->company->getKey())->selectable()->count())->toBe(2);
    });

    it('scopes active to active only', function (): void {
        Supplier::factory()->create(['company_id' => $this->company->getKey()]);
        Supplier::factory()->inactive()->create(['company_id' => $this->company->getKey()]);

        expect(Supplier::query()->forCompany($this->company->getKey())->active()->count())->toBe(1);
    });

    it('scopes to a company', function (): void {
        Supplier::factory()->create(['company_id' => $this->company->getKey()]);

        expect(Supplier::query()->forCompany($this->company->getKey())->count())->toBe(1);
    });
});

describe('soft deletes', function (): void {
    it('hides a soft-deleted supplier from the default scope but keeps it with trashed', function (): void {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
        $supplier->delete();

        expect(Supplier::query()->find($supplier->getKey()))->toBeNull()
            ->and(Supplier::query()->withTrashed()->find($supplier->getKey()))->not->toBeNull();
    });
});

describe('audit surface', function (): void {
    it('includes the tax identification number in auditOnly (Gate 2 divergence)', function (): void {
        // The one deliberate departure from the literal customer mirror, confirmed at Gate 2: the TIN is
        // retained for later WHT/compliance, so a changed TIN must be audited (ADR 0018 §C2 / §H item 5).
        expect((new Supplier)->auditOnly())->toContain('tax_identification_number');
    });

    it('audits the customer-mirrored change-worthy columns', function (): void {
        $auditOnly = (new Supplier)->auditOnly();

        expect($auditOnly)->toContain('code')
            ->and($auditOnly)->toContain('name')
            ->and($auditOnly)->toContain('legal_name')
            ->and($auditOnly)->toContain('vat_registration_number')
            ->and($auditOnly)->toContain('is_vat_registered')
            ->and($auditOnly)->toContain('payment_terms_days')
            ->and($auditOnly)->toContain('status');
    });

    it('does not audit the deferred columns', function (): void {
        $auditOnly = (new Supplier)->auditOnly();

        expect($auditOnly)->not->toContain('credit_limit')
            ->and($auditOnly)->not->toContain('receivable_account_id');
    });

    it('tags audit entries for the purchasing supplier domain', function (): void {
        expect((new Supplier)->auditTags())->toBe(['purchasing', 'supplier']);
    });
});

describe('the morph alias', function (): void {
    it('round-trips through the enforced morph map', function (): void {
        // `Supplier` applies `Auditable`, and the enforced morph map means an audit entry for an unmapped
        // class throws rather than storing a class name a namespace refactor would orphan (ADR 0018 §A4).
        expect(Supplier::MORPH_ALIAS)->toBe('supplier')
            ->and(Relation::getMorphedModel(Supplier::MORPH_ALIAS))->toBe(Supplier::class);
    });
});
