<?php

declare(strict_types=1);

use Asids\Core\Purchasing\Domain\Enums\SupplierStatus;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Illuminate\Database\QueryException;

/**
 * That `SupplierFactory` produces rows the database and the domain both accept — Stage 2 of Wave 6.
 *
 * The payable-side mirror of `CustomerFactory`. An unexercised factory is a liability rather than a
 * convenience: Wave 7 will build bill fixtures on top of it, and a factory that produced rows violating
 * a CHECK — or one that tripped `Model::shouldBeStrict()` on a read-before-refresh of a defaulted
 * column — would surface as a confusing failure inside bill tests rather than here (ADR 0018 §B6, §G).
 *
 * RED expectation before Stage 2 lands: `Database\Factories\SupplierFactory` and the `Supplier` model
 * do not exist.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
});

it('produces a valid active supplier by default', function (): void {
    $supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

    expect($supplier->exists)->toBeTrue()
        ->and($supplier->status)->toBe(SupplierStatus::Active)
        ->and($supplier->archived_at)->toBeNull()
        ->and($supplier->is_vat_registered)->toBeFalse();
});

it('sets status and archived_at explicitly so an unsaved instance is strict-mode-safe', function (): void {
    // The trap Phase 1 hit on `must_change_password` and Phase 2 on `is_closed`: an unsaved model returns
    // null for a defaulted column and reading it back before a refresh throws under `shouldBeStrict()`.
    // The factory sets both explicitly (ADR 0018 §B6), so `make()` — which never touches the database —
    // reads them back without throwing.
    $supplier = Supplier::factory()->make(['company_id' => $this->company->getKey()]);

    expect($supplier->status)->toBe(SupplierStatus::Active)
        ->and($supplier->archived_at)->toBeNull();
});

it('inherits the tenant from the active context', function (): void {
    $supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

    // `BelongsToTenant` supplies `tenant_id`, which is why the factory does not — and must not, since a
    // hardcoded tenant would defeat the isolation the trait exists to enforce.
    expect($supplier->tenant_id)->toBe($this->acme['tenant']->getKey());
});

it('requires a company, and says so rather than inventing one', function (): void {
    // The contract, asserted so it is documented rather than discovered. `CustomerFactory` behaves
    // identically: a supplier scoped to no company is a supplier no bill could legitimately name.
    expect(fn () => Supplier::factory()->create())
        ->toThrow(QueryException::class);
});

it('produces codes that do not collide with each other or with generated S- codes', function (): void {
    $first = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
    $second = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

    // Random rather than sequential: a factory that generated `S-0001` would collide with the codes
    // `SupplierService` derives, and the collision would only appear in tests that mix the two.
    expect($second->code)->not->toBe($first->code)
        ->and(Supplier::query()->forCompany($this->company->getKey())->count())->toBe(2);
});

it('produces valid rows for every named state', function (): void {
    $vatRegistered = Supplier::factory()->vatRegistered()->create(['company_id' => $this->company->getKey()]);
    $inactive = Supplier::factory()->inactive()->create(['company_id' => $this->company->getKey()]);
    $archived = Supplier::factory()->archived()->create(['company_id' => $this->company->getKey()]);

    expect($vatRegistered->is_vat_registered)->toBeTrue()
        // The VAT check requires a number whenever the flag is set.
        ->and($vatRegistered->vat_registration_number)->not->toBeNull()
        ->and($inactive->status)->toBe(SupplierStatus::Inactive)
        // The archive CHECK requires status and the timestamp to move together.
        ->and($archived->status)->toBe(SupplierStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull();
});

it('sets the payment terms via the onTerms state', function (): void {
    $supplier = Supplier::factory()->onTerms(60)->create(['company_id' => $this->company->getKey()]);

    expect($supplier->payment_terms_days)->toBe(60);
});
