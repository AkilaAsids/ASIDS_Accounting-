<?php

declare(strict_types=1);

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Infrastructure\NoPayables;

/**
 * The payables probe, dormant — Stage 3 of Wave 6 (ADR 0018 §E, §F).
 *
 * The archive, delete and code-lock rules depend on bills, which do not exist until Wave 7. They are
 * built now against a seam, bound to `NoPayables`, which truthfully reports that no bill table exists:
 * nobody owes anything, and no supplier has been named on a bill. This is the exact pattern
 * `ReceivableBalanceProbe` documents, warning that "a constraint with nothing to enforce it on day one
 * is usually a constraint that never arrives."
 *
 * This file is the mirror of the *binding* assertion in `ReceivableBalanceProbeTest.php:89-94` — the one
 * assertion that would have caught Sales closing a milestone with the seam still unbound. The full
 * bills-driven probe suite is Wave 7's, when `EloquentPayableBalanceProbe` exists.
 *
 * RED expectation before Stage 3 lands: `PayableBalanceProbe` / `NoPayables` do not exist and nothing is
 * bound in the container.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

    $this->probe = app(PayableBalanceProbe::class);
});

describe('the binding', function (): void {
    it('is the dormant NoPayables, not a real implementation yet', function (): void {
        // Wave 7 flips this one line to `EloquentPayableBalanceProbe`, and the archive/delete/code-lock
        // rules begin to bite with not a line of `SupplierService` changing (ADR 0018 §E).
        expect(app(PayableBalanceProbe::class))->toBeInstanceOf(NoPayables::class);
    });
});

describe('what a supplier is owed', function (): void {
    it('reports zero as a scaled decimal string, never an integer or float', function (): void {
        $balance = $this->probe->outstandingBalance($this->supplier);

        // `SupplierService` compares this with `bccomp` at `Money::SCALE`, which needs the scale actually
        // present — an integer 0 or a float would compare differently.
        expect($balance)->toBeString()
            ->and($balance)->toBe('0.0000')
            ->and(substr($balance, strpos($balance, '.') + 1))->toHaveLength(Money::SCALE);
    });
});

describe('whether a supplier has ever been billed', function (): void {
    it('is false, because no bill table exists yet', function (): void {
        expect($this->probe->hasAnyBill($this->supplier))->toBeFalse();
    });
});
