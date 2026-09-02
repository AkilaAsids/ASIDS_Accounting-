<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\BillLine;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * That `BillFactory`/`BillLineFactory` produce rows the database and the domain both accept — Stage 3 of Wave 7.
 *
 * The payable-side mirror of `SalesInvoiceFactoryTest`. An unexercised factory is a liability: later fixtures
 * (post/probe/authorization) build on these, and a factory producing rows that violate a CHECK — or one that
 * trips `Model::shouldBeStrict()` on a read-before-refresh of a defaulted column — would surface as a confusing
 * failure inside *those* tests rather than here.
 *
 * RED expectation before Stage 3 lands: `Bill`/`BillLine` and their factories do not exist.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
    $this->expense = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();

    $this->references = [
        'company_id' => $this->company->getKey(),
        'supplier_id' => $this->supplier->getKey(),
    ];
});

describe('the bill factory', function (): void {
    it('produces a valid zero-total draft', function (): void {
        // Zero because the totals belong to the lines; a factory inventing figures would produce a header that
        // disagreed with its lines, which the CHECKs would refuse — or worse, would not.
        $bill = Bill::factory()->create($this->references);

        expect($bill->exists)->toBeTrue()
            ->and($bill->status)->toBe(BillStatus::Draft)
            ->and($bill->total)->toBe('0.0000')
            ->and($bill->amount_due)->toBe('0.0000')
            ->and($bill->number)->toBeNull()
            ->and($bill->posted_at)->toBeNull()
            ->and($bill->journal_entry_id)->toBeNull()
            // A draft still needs the supplier's own number — it is NOT NULL from creation (ADR §A2).
            ->and($bill->supplier_invoice_number)->not->toBeNull();
    });

    it('sets status explicitly so an unsaved instance is strict-mode-safe', function (): void {
        // The trap Phase 1 hit on `must_change_password`: an unsaved model returns null for a defaulted column
        // and reading it back before a refresh throws under `shouldBeStrict()`. `make()` never touches the DB.
        $bill = Bill::factory()->make($this->references);

        expect($bill->status)->toBe(BillStatus::Draft);
    });

    it('inherits the tenant from the active context', function (): void {
        $bill = Bill::factory()->create($this->references);

        expect($bill->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('requires a company and a supplier rather than inventing them', function (): void {
        expect(fn () => Bill::factory()->create())
            ->toThrow(QueryException::class);
    });

    it('satisfies the money invariants the database asserts', function (): void {
        $bill = Bill::factory()->create($this->references);

        expect(bcadd($bill->subtotal, $bill->tax_total, 4))->toBe($bill->total)
            ->and(bcsub($bill->total, $bill->amount_paid, 4))->toBe($bill->amount_due);
    });

    it('honours the date state', function (): void {
        $bill = Bill::factory()->on('2026-03-01', '2026-04-01')->create($this->references);

        expect($bill->bill_date->toDateString())->toBe('2026-03-01')
            ->and($bill->due_date->toDateString())->toBe('2026-04-01');
    });

    it('produces drafts that do not collide on the number index', function (): void {
        Bill::factory()->count(3)->create($this->references);

        // Every draft has a null number; the unique index is partial for exactly that reason.
        expect(Bill::query()->forCompany($this->company->getKey())->count())->toBe(3)
            ->and(DB::table('bills')->whereNull('number')->count())->toBe(3);
    });

    it('produces a valid posted bill via the posted state', function (): void {
        // The `posted` state carries a number and a posted timestamp so the status-tied CHECKs hold (ADR §C2).
        $bill = Bill::factory()->posted()->create($this->references);

        expect($bill->status)->toBe(BillStatus::Posted)
            ->and($bill->number)->not->toBeNull()
            ->and($bill->posted_at)->not->toBeNull();
    });
});

describe('the line factory', function (): void {
    it('produces a valid line', function (): void {
        $bill = Bill::factory()->create($this->references);

        $line = BillLine::factory()->create([
            'company_id' => $this->company->getKey(),
            'bill_id' => $bill->getKey(),
            'expense_account_id' => $this->expense->getKey(),
        ]);

        expect($line->exists)->toBeTrue()
            ->and($line->tenant_id)->toBe($this->acme['tenant']->getKey())
            ->and($line->line_number)->toBe(1);
    });

    it('satisfies the line total invariant', function (): void {
        $bill = Bill::factory()->create($this->references);

        $line = BillLine::factory()->create([
            'company_id' => $this->company->getKey(),
            'bill_id' => $bill->getKey(),
            'expense_account_id' => $this->expense->getKey(),
        ]);

        expect($line->line_total)->toBe(bcadd($line->line_subtotal, $line->tax_amount, 4));
    });

    it('requires a bill, a company and an expense account', function (): void {
        expect(fn () => BillLine::factory()->create())
            ->toThrow(QueryException::class);
    });

    it('places lines at distinct positions', function (): void {
        $bill = Bill::factory()->create($this->references);
        $shared = [
            'company_id' => $this->company->getKey(),
            'bill_id' => $bill->getKey(),
            'expense_account_id' => $this->expense->getKey(),
        ];

        BillLine::factory()->atPosition(1)->create($shared);
        BillLine::factory()->atPosition(2)->create($shared);

        expect($bill->fresh()->lines->pluck('line_number')->all())->toBe([1, 2]);
    });

    it('dies with its bill', function (): void {
        $bill = Bill::factory()->create($this->references);
        BillLine::factory()->create([
            'company_id' => $this->company->getKey(),
            'bill_id' => $bill->getKey(),
            'expense_account_id' => $this->expense->getKey(),
        ]);

        $bill->delete();

        expect(DB::table('bill_lines')->count())->toBe(0);
    });
});
