<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the database refuses about an issued invoice, and the header arithmetic it now enforces.
 *
 * Stage 1 of Milestone 5. Nothing here issues an invoice through a service — `issue()` does not exist yet. The
 * issued states are planted with raw SQL, which is the only way to reach them at this stage and also the right
 * way to test a trigger: every assertion bypasses the application entirely, so a pass means the database is doing
 * the work rather than a service being careful.
 *
 * The distinction being proved is that a draft stays freely editable while an issued invoice is frozen apart
 * from the three columns payments will need. Get that boundary wrong in either direction and Milestone 5 either
 * cannot issue at all, or can silently rewrite a document the customer already holds.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();

    app(ChartTemplateService::class)->apply($this->company);

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

    $this->customer = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );
});

/**
 * A draft built through the service, so its figures are real.
 */
function stagedDraft(string $unitPrice = '1000.00'): SalesInvoice
{
    return app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
        )],
    ));
}

/**
 * Forces a draft into an issued state with raw SQL.
 *
 * The trigger's `WHEN (OLD.status <> 'draft')` clause means this very update is not caught — which is exactly the
 * property `issue()` will depend on in Stage 3, and worth exercising here.
 */
function forceIssued(string $invoiceId, string $number = 'INV-2026-06-0001'): void
{
    DB::table('sales_invoices')->where('id', $invoiceId)->update([
        'status' => SalesInvoiceStatus::Issued->value,
        'number' => $number,
        'issued_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('the document type', function (): void {
    it('carries the INV prefix and demands gapless numbering', function (): void {
        expect(DocumentType::SalesInvoice->value)->toBe('sales_invoice')
            ->and(DocumentType::SalesInvoice->prefix())->toBe('INV')
            ->and(DocumentType::SalesInvoice->label())->toBe('Sales invoice')
            // Sri Lankan e-invoicing demands completeness, so the row-lock cost of gapless numbering is one
            // this family has to pay.
            ->and(DocumentType::SalesInvoice->requiresGaplessNumbering())->toBeTrue();
    });

    it('leaves the existing families untouched', function (): void {
        // The change to the Accounting enum is additive, in Milestone 5 and again in Phase 4. Asserted because
        // it is the one place these waves reach into proven ledger code. Phase 4 added `CustomerReceipt` (RCT)
        // alongside Milestone 5's `SalesInvoice` (INV), so the count is now five — the original three families
        // remain exactly as they were.
        expect(DocumentType::JournalVoucher->prefix())->toBe('JV')
            ->and(DocumentType::OpeningBalance->prefix())->toBe('OB')
            ->and(DocumentType::YearEndClose->prefix())->toBe('YEC')
            ->and(DocumentType::CustomerReceipt->prefix())->toBe('RCT')
            ->and(DocumentType::cases())->toHaveCount(5);
    });
});

describe('the header arithmetic', function (): void {
    it('accepts a header whose total agrees with its parts', function (): void {
        // Built by the service, so this is the ordinary case rather than a contrived one.
        $invoice = stagedDraft();

        expect(bcadd($invoice->subtotal, $invoice->tax_total, 4))->toBe($invoice->total);
    });

    it('refuses a total that disagrees with subtotal plus tax', function (): void {
        $invoice = stagedDraft();

        // Issuing posts the ledger from these figures. A header disagreeing with itself would debit receivables
        // by one amount and credit revenue and tax by another, and the deferred balance trigger would refuse the
        // entry — leaving an invoice that cannot be issued and no explanation why.
        expect(fn () => DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'total' => bcadd($invoice->total, '1.0000', 4),
        ]))->toThrow(QueryException::class);
    });

    it('refuses a subtotal changed without the total following', function (): void {
        $invoice = stagedDraft();

        expect(fn () => DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'subtotal' => bcadd($invoice->subtotal, '5.0000', 4),
        ]))->toThrow(QueryException::class);
    });

    it('accepts a coherent change to all three together', function (): void {
        $invoice = stagedDraft();

        // A draft is still editable, and the constraint only asks that the figures agree — not that they never
        // move.
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'subtotal' => '2000.0000',
            'tax_total' => '0.0000',
            'total' => '2000.0000',
            'amount_due' => '2000.0000',
        ]);

        expect(DB::table('sales_invoices')->where('id', $invoice->getKey())->value('total'))->toBe('2000.0000');
    });
});

describe('a draft remains editable', function (): void {
    it('permits changing its contents', function (): void {
        $invoice = stagedDraft();

        DB::table('sales_invoices')->where('id', $invoice->getKey())->update(['reference' => 'PO-4471']);

        expect(DB::table('sales_invoices')->where('id', $invoice->getKey())->value('reference'))->toBe('PO-4471');
    });

    it('permits changing and removing its lines', function (): void {
        $invoice = stagedDraft();

        DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())
            ->update(['description' => 'Revised description']);
        DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())->delete();

        expect(DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())->count())->toBe(0);
    });

    it('permits being hard-deleted', function (): void {
        $invoice = stagedDraft();

        DB::table('sales_invoices')->where('id', $invoice->getKey())->delete();

        expect(DB::table('sales_invoices')->where('id', $invoice->getKey())->count())->toBe(0);
    });

    it('permits the issuing transition itself', function (): void {
        $invoice = stagedDraft();

        // The property Stage 3 depends on: `WHEN (OLD.status <> 'draft')` means the transition out of draft is
        // not caught, so one UPDATE may set status, number and issued_at together.
        forceIssued((string) $invoice->getKey());

        expect(DB::table('sales_invoices')->where('id', $invoice->getKey())->value('status'))
            ->toBe(SalesInvoiceStatus::Issued->value);
    });
});

describe('an issued invoice is frozen', function (): void {
    beforeEach(function (): void {
        $this->invoice = stagedDraft();
        forceIssued((string) $this->invoice->getKey());
        $this->id = (string) $this->invoice->getKey();
    });

    it('refuses every accounting-bearing change', function (string $column, mixed $value): void {
        expect(fn () => DB::table('sales_invoices')->where('id', $this->id)->update([$column => $value]))
            ->toThrow(QueryException::class);
    })->with([
        ['reference', 'PO-CHANGED'],
        ['invoice_date', '2026-07-01'],
        ['due_date', '2026-08-01'],
        ['subtotal', '9999.0000'],
        ['tax_total', '1.0000'],
        ['discount_total', '5.0000'],
        ['number', 'INV-2026-06-9999'],
        ['currency_code', 'USD'],
        ['notes', 'Changed after issuing'],
        ['terms', 'Changed after issuing'],
    ]);

    it('refuses a change to the total even when arithmetically coherent', function (): void {
        // The CHECK would be satisfied; the trigger refuses anyway. The customer holds a copy and the ledger
        // holds a posting derived from these figures — an edit makes three records disagree.
        expect(fn () => DB::table('sales_invoices')->where('id', $this->id)->update([
            'subtotal' => '2000.0000',
            'total' => '2000.0000',
            'amount_due' => '2000.0000',
        ]))->toThrow(QueryException::class);
    });

    it('refuses deletion outright', function (): void {
        expect(fn () => DB::table('sales_invoices')->where('id', $this->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses a return to draft', function (): void {
        // A number has been consumed and, from Stage 3, a ledger entry exists. Un-issuing would strand both.
        expect(fn () => DB::table('sales_invoices')->where('id', $this->id)->update([
            'status' => SalesInvoiceStatus::Draft->value,
        ]))->toThrow(QueryException::class);
    });

    it('refuses any change to its lines', function (): void {
        expect(fn () => DB::table('sales_invoice_lines')->where('sales_invoice_id', $this->id)
            ->update(['quantity' => '99.0000']))->toThrow(QueryException::class);
    });

    it('refuses deleting a line', function (): void {
        expect(fn () => DB::table('sales_invoice_lines')->where('sales_invoice_id', $this->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses adding a line', function (): void {
        // The gap a delete-only guard would leave: appending to a posted document changes what it says without
        // touching anything already there.
        expect(fn () => DB::table('sales_invoice_lines')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $this->id,
            'line_number' => 99,
            'description' => 'Smuggled in after issuing',
            'quantity' => '1.0000',
            'unit_price' => '500.0000',
            'line_subtotal' => '500.0000',
            'tax_rate' => '0.0000',
            'tax_amount' => '0.0000',
            'line_total' => '500.0000',
            'revenue_account_id' => $this->revenue->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('the transitions an issued invoice may still make', function (): void {
    beforeEach(function (): void {
        $this->invoice = stagedDraft();
        forceIssued((string) $this->invoice->getKey());
        $this->id = (string) $this->invoice->getKey();
    });

    it('permits cancellation', function (): void {
        // Stage 4 added `sales_invoices_cancellation_matches_status_check`, so a cancelled invoice must carry
        // its cancellation record. Planted here rather than asserted — this test is about what happens *after*
        // cancellation, and a half-cancelled row is a state the database no longer allows to exist.
        DB::table('sales_invoices')->where('id', $this->id)->update([
            'status' => SalesInvoiceStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled for the purposes of this test',
            'updated_at' => now(),
        ]);

        expect(DB::table('sales_invoices')->where('id', $this->id)->value('status'))
            ->toBe(SalesInvoiceStatus::Cancelled->value);
    });

    it('permits the payment statuses Phase 4 will use', function (string $status): void {
        DB::table('sales_invoices')->where('id', $this->id)->update(['status' => $status, 'updated_at' => now()]);

        expect(DB::table('sales_invoices')->where('id', $this->id)->value('status'))->toBe($status);
    })->with([
        SalesInvoiceStatus::PartiallyPaid->value,
        SalesInvoiceStatus::Paid->value,
    ]);

    it('lets the payment figures move now the payments phase has arrived, but never past the total', function (): void {
        // Milestone 4 pinned these columns at zero with a phase-scoped CHECK, permitting them on the trigger so
        // Phase 4 would add behaviour rather than loosen a trigger. Phase 4 has arrived: it dropped that CHECK,
        // so a partial payment on an issued invoice is now representable.
        DB::table('sales_invoices')->where('id', $this->id)
            ->update(['amount_paid' => '100.0000', 'amount_due' => '900.0000']);

        expect(DB::table('sales_invoices')->where('id', $this->id)->value('amount_paid'))->toBe('100.0000');

        // Its replacement is the AC-5.2 backstop `amount_paid <= total`, which refuses driving an invoice past
        // its total (equivalently `amount_due` negative) regardless of what any service does — the guard that
        // stops two racing receipts overselling one invoice.
        expect(fn () => DB::table('sales_invoices')->where('id', $this->id)
            ->update(['amount_paid' => '1100.0000', 'amount_due' => '-100.0000']))
            ->toThrow(QueryException::class);
    });

    it('treats a cancelled invoice as final', function (): void {
        // Stage 4 added `sales_invoices_cancellation_matches_status_check`, so a cancelled invoice must carry
        // its cancellation record. Planted here rather than asserted — this test is about what happens *after*
        // cancellation, and a half-cancelled row is a state the database no longer allows to exist.
        DB::table('sales_invoices')->where('id', $this->id)->update([
            'status' => SalesInvoiceStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled for the purposes of this test',
            'updated_at' => now(),
        ]);

        // Its posting has already been reversed. A further transition would double-reverse or resurrect a
        // document whose reversal is in the books.
        expect(fn () => DB::table('sales_invoices')->where('id', $this->id)
            ->update(['status' => SalesInvoiceStatus::Issued->value]))
            ->toThrow(QueryException::class);
    });

    it('keeps the number on a cancelled invoice', function (): void {
        // Stage 4 added `sales_invoices_cancellation_matches_status_check`, so a cancelled invoice must carry
        // its cancellation record. Planted here rather than asserted — this test is about what happens *after*
        // cancellation, and a half-cancelled row is a state the database no longer allows to exist.
        DB::table('sales_invoices')->where('id', $this->id)->update([
            'status' => SalesInvoiceStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled for the purposes of this test',
            'updated_at' => now(),
        ]);

        // Releasing it would leave a gap in a series a tax authority audits for completeness.
        expect(DB::table('sales_invoices')->where('id', $this->id)->value('number'))
            ->toBe('INV-2026-06-0001');
    });
});

describe('tenant isolation enforced by the database', function (): void {
    it('still hides another workspace’s issued invoices', function (): void {
        $invoice = stagedDraft();
        forceIssued((string) $invoice->getKey());

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // Re-verified after adding triggers: a BEFORE trigger runs after the row-level policy has already
        // filtered, so the two cannot mask each other — asserted rather than assumed.
        expect(DB::table('sales_invoices')->count())->toBe(0)
            ->and(DB::table('sales_invoice_lines')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('sales_invoices'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
