<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the database refuses about a sales invoice.
 *
 * Stage 1 of Milestone 4. Every insert here goes through the query builder, bypassing the model, the DTO and
 * the service that do not exist yet — so a passing test means the constraint is doing the work rather than
 * the application being polite. That matters in a table a bulk import, a data fix and a future service will
 * all write to.
 *
 * The constraints proving the *issued boundary* are the point of this file. Milestone 4 cannot issue
 * anything, so nothing here exercises issuing behaviour; what it does prove is that the states Milestone 5
 * will move through are the only ones representable, and every partial version of that transition is already
 * impossible.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();

    app(ChartTemplateService::class)->apply($this->company);

    $this->customer = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->revenue = Account::query()
        ->forCompany($this->company->getKey())
        ->where('code', '4100')
        ->firstOrFail();
});

/**
 * A raw draft invoice row. Named distinctly from the tax-code helpers, since Pest helpers are global.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoiceRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'customer_id' => test()->customer->getKey(),
        'number' => null,
        'invoice_date' => '2026-06-15',
        'due_date' => '2026-07-15',
        'currency_code' => 'LKR',
        'subtotal' => '1000.0000',
        'discount_total' => '0.0000',
        'tax_total' => '180.0000',
        'total' => '1180.0000',
        'amount_paid' => '0.0000',
        'amount_due' => '1180.0000',
        'status' => SalesInvoiceStatus::Draft->value,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

function insertInvoice(array $overrides = []): string
{
    $row = invoiceRow($overrides);
    DB::table('sales_invoices')->insert($row);

    return (string) $row['id'];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertLine(string $invoiceId, array $overrides = []): void
{
    DB::table('sales_invoice_lines')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'sales_invoice_id' => $invoiceId,
        'line_number' => 1,
        'description' => 'Consulting services',
        'quantity' => '1.0000',
        'unit_price' => '1000.0000',
        'line_subtotal' => '1000.0000',
        'tax_rate' => '0.0000',
        'tax_amount' => '0.0000',
        'line_total' => '1000.0000',
        'revenue_account_id' => test()->revenue->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

describe('a draft invoice', function (): void {
    it('accepts a well-formed draft', function (): void {
        insertInvoice();

        expect(DB::table('sales_invoices')->count())->toBe(1);
    });

    it('accepts a draft with no number', function (): void {
        $id = insertInvoice();

        // Gapless numbering is reserved inside the Milestone 5 issuing transaction, so an abandoned draft
        // consumes none — a number handed to a draft later deleted leaves a gap a tax authority may audit for.
        expect(DB::table('sales_invoices')->where('id', $id)->value('number'))->toBeNull();
    });

    it('refuses a due date before the invoice date', function (): void {
        expect(fn () => insertInvoice(['invoice_date' => '2026-07-15', 'due_date' => '2026-06-15']))
            ->toThrow(QueryException::class);
    });

    it('accepts a due date equal to the invoice date', function (): void {
        // Due on receipt is a real term, not a missing value.
        insertInvoice(['invoice_date' => '2026-06-15', 'due_date' => '2026-06-15']);

        expect(DB::table('sales_invoices')->count())->toBe(1);
    });

    it('refuses a status outside the enum', function (): void {
        expect(fn () => insertInvoice(['status' => 'sent']))
            ->toThrow(QueryException::class);
    });

    it('accepts every status the enum declares', function (string $status): void {
        // All five exist from the start so Milestone 5 adds issuing as behaviour rather than a migration.
        // The non-draft states need the columns the boundary constraints tie to `status`.
        $overrides = $status === SalesInvoiceStatus::Draft->value
            ? []
            : ['status' => $status, 'number' => 'INV-2026-06-0001', 'issued_at' => now()];

        if ($status === SalesInvoiceStatus::Cancelled->value) {
            // Stage 4 tied the cancellation record to the status as well, so `cancelled` needs its own two
            // columns for the same reason the others need a number and a timestamp.
            $overrides['cancelled_at'] = now();
            $overrides['cancellation_reason'] = 'Cancelled for the purposes of this test';
        }

        insertInvoice($overrides === [] ? [] : $overrides);

        expect(DB::table('sales_invoices')->where('status', $status)->count())->toBe(1);
    })->with([
        SalesInvoiceStatus::Draft->value,
        SalesInvoiceStatus::Issued->value,
        SalesInvoiceStatus::PartiallyPaid->value,
        SalesInvoiceStatus::Paid->value,
        SalesInvoiceStatus::Cancelled->value,
    ]);
});

describe('the issued boundary', function (): void {
    it('refuses a draft that carries a number', function (): void {
        // The state a half-written issuing transition would produce.
        expect(fn () => insertInvoice(['number' => 'INV-2026-06-0001']))
            ->toThrow(QueryException::class);
    });

    it('refuses an issued invoice with no number', function (): void {
        expect(fn () => insertInvoice([
            'status' => SalesInvoiceStatus::Issued->value,
            'number' => null,
            'issued_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses a draft that carries an issued timestamp', function (): void {
        expect(fn () => insertInvoice(['issued_at' => now()]))
            ->toThrow(QueryException::class);
    });

    it('refuses an issued invoice with no issued timestamp', function (): void {
        expect(fn () => insertInvoice([
            'status' => SalesInvoiceStatus::Issued->value,
            'number' => 'INV-2026-06-0001',
            'issued_at' => null,
        ]))->toThrow(QueryException::class);
    });

    it('keeps the number and timestamp on a cancelled invoice', function (): void {
        // A cancelled invoice *was* issued. It keeps its number, because releasing it would leave a gap, and
        // keeps its ledger entry alongside the reversal. Only `draft` means never issued.
        insertInvoice([
            'status' => SalesInvoiceStatus::Cancelled->value,
            'number' => 'INV-2026-06-0001',
            'issued_at' => now(),
            // Required from Stage 4 onwards: `sales_invoices_cancellation_matches_status_check` ties the
            // cancellation record to the status, so a cancelled invoice cannot exist without one.
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled for the purposes of this test',
        ]);

        expect(DB::table('sales_invoices')->value('number'))->toBe('INV-2026-06-0001');
    });

    it('refuses a draft already pointing at a ledger entry', function (): void {
        expect(fn () => insertInvoice(['journal_entry_id' => (string) Str::uuid7()]))
            ->toThrow(QueryException::class);
    });
});

describe('invoice numbers are unique per company once issued', function (): void {
    it('refuses two issued invoices sharing a number', function (): void {
        insertInvoice(['status' => SalesInvoiceStatus::Issued->value, 'number' => 'INV-0001', 'issued_at' => now()]);

        expect(fn () => insertInvoice([
            'status' => SalesInvoiceStatus::Issued->value,
            'number' => 'INV-0001',
            'issued_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('allows many drafts, which all have a null number', function (): void {
        // The index is partial for exactly this reason: a plain unique would permit one draft per company.
        insertInvoice();
        insertInvoice();
        insertInvoice();

        expect(DB::table('sales_invoices')->whereNull('number')->count())->toBe(3);
    });
});

describe('the money invariants', function (): void {
    it('refuses an amount due that disagrees with the total', function (): void {
        // A total that disagrees with what is outstanding is a figure two reports will disagree about.
        expect(fn () => insertInvoice(['total' => '1180.0000', 'amount_due' => '1000.0000']))
            ->toThrow(QueryException::class);
    });

    it('refuses a negative total', function (): void {
        expect(fn () => insertInvoice(['subtotal' => '-1000.0000', 'total' => '-1000.0000', 'amount_due' => '-1000.0000']))
            ->toThrow(QueryException::class);
    });

    it('accepts a zero total', function (): void {
        // A draft under construction, or one fully discounted. Refusing zero would block legitimate
        // work in progress; the positive-total rule belongs to issuing.
        insertInvoice(['subtotal' => '0.0000', 'tax_total' => '0.0000', 'total' => '0.0000', 'amount_due' => '0.0000']);

        expect(DB::table('sales_invoices')->count())->toBe(1);
    });

    it('holds payments at zero until the payments phase', function (): void {
        // A phase-scoped constraint the payments phase drops. The columns ship now so Phase 4 adds behaviour
        // rather than a migration to a populated table, and this makes it impossible to half-use them meanwhile.
        expect(fn () => insertInvoice(['amount_paid' => '100.0000', 'amount_due' => '1080.0000']))
            ->toThrow(QueryException::class);
    });

    it('holds the exchange rate at null until the FX phase', function (): void {
        expect(fn () => insertInvoice(['exchange_rate' => '1.0000000000']))
            ->toThrow(QueryException::class);
    });
});

describe('invoice lines', function (): void {
    it('accepts a well-formed line', function (): void {
        insertLine(insertInvoice());

        expect(DB::table('sales_invoice_lines')->count())->toBe(1);
    });

    it('refuses a zero quantity', function (): void {
        // Almost always a half-finished entry, and it contributes nothing.
        expect(fn () => insertLine(insertInvoice(), ['quantity' => '0.0000']))
            ->toThrow(QueryException::class);
    });

    it('accepts a negative quantity', function (): void {
        // How a line-level correction is expressed on an otherwise positive invoice.
        insertLine(insertInvoice(), [
            'quantity' => '-1.0000',
            'line_subtotal' => '-1000.0000',
            'line_total' => '-1000.0000',
        ]);

        expect(DB::table('sales_invoice_lines')->count())->toBe(1);
    });

    it('refuses both discount forms at once', function (): void {
        // A percentage is negotiated, a fixed amount approved. Storing both leaves the question of which won.
        expect(fn () => insertLine(insertInvoice(), ['discount_percent' => '10.0000', 'discount_amount' => '50.0000']))
            ->toThrow(QueryException::class);
    });

    it('accepts either discount form alone', function (): void {
        $first = insertInvoice();
        insertLine($first, ['discount_percent' => '10.0000']);
        insertLine($first, ['line_number' => 2, 'discount_amount' => '50.0000']);

        expect(DB::table('sales_invoice_lines')->count())->toBe(2);
    });

    it('refuses a discount percentage above one hundred', function (): void {
        expect(fn () => insertLine(insertInvoice(), ['discount_percent' => '100.0001']))
            ->toThrow(QueryException::class);
    });

    it('refuses a tax rate above one hundred', function (): void {
        // Bounded as `tax_codes.rate` is: a rate entered as basis points would multiply the line by eighteen.
        expect(fn () => insertLine(insertInvoice(), ['tax_rate' => '1800.0000']))
            ->toThrow(QueryException::class);
    });

    it('refuses a tax rate with no tax code to attribute it to', function (): void {
        // The shape a partially-applied edit produces, and the shape a VAT return cannot explain.
        expect(fn () => insertLine(insertInvoice(), ['tax_code_id' => null, 'tax_rate' => '18.0000']))
            ->toThrow(QueryException::class);
    });

    it('refuses a line total that disagrees with its parts', function (): void {
        expect(fn () => insertLine(insertInvoice(), [
            'line_subtotal' => '1000.0000',
            'tax_amount' => '180.0000',
            'line_total' => '1000.0000',
        ]))->toThrow(QueryException::class);
    });

    it('refuses two lines at the same position', function (): void {
        $invoice = insertInvoice();
        insertLine($invoice, ['line_number' => 1]);

        // Without this a reordering bug produces two line 3s and the document reprints in an order nobody chose.
        expect(fn () => insertLine($invoice, ['line_number' => 1]))
            ->toThrow(QueryException::class);
    });

    it('allows the same position on different invoices', function (): void {
        insertLine(insertInvoice(), ['line_number' => 1]);
        insertLine(insertInvoice(), ['line_number' => 1]);

        expect(DB::table('sales_invoice_lines')->count())->toBe(2);
    });

    it('requires a revenue account', function (): void {
        expect(fn () => insertLine(insertInvoice(), ['revenue_account_id' => null]))
            ->toThrow(QueryException::class);
    });

    it('dies with its invoice', function (): void {
        $invoice = insertInvoice();
        insertLine($invoice);

        DB::table('sales_invoices')->where('id', $invoice)->delete();

        // Cascade, unlike every other foreign key in the module: a line has no meaning apart from its
        // invoice, and a draft's lines should die with the draft.
        expect(DB::table('sales_invoice_lines')->count())->toBe(0);
    });
});

describe('referential integrity', function (): void {
    it('refuses an invoice for a customer that does not exist', function (): void {
        expect(fn () => insertInvoice(['customer_id' => (string) Str::uuid7()]))
            ->toThrow(QueryException::class);
    });

    it('refuses to delete a customer that has an invoice', function (): void {
        insertInvoice();

        // Restrict, not cascade. An invoice names its customer and that name has to stay resolvable — this is
        // the guarantee behind `CustomerService` refusing the delete, not a duplicate of it.
        expect(fn () => DB::table('customers')->where('id', $this->customer->getKey())->delete())
            ->toThrow(QueryException::class);
    });
});

describe('tenant isolation enforced by the database', function (): void {
    it('hides another workspace’s invoices from raw SQL', function (): void {
        insertInvoice();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('sales_invoices')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('sales_invoices'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('hides another workspace’s invoice lines from raw SQL', function (): void {
        insertLine(insertInvoice());

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // Lines carry their own policy rather than relying on the parent's. Row level security is not
        // transitive: a report joining from lines upward would otherwise be unprotected.
        expect(DB::table('sales_invoice_lines')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('sales_invoice_lines'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('refuses a write naming another tenant', function (): void {
        $acmeTenantId = $this->tenantId;
        $acmeCompanyId = $this->company->getKey();
        $acmeCustomerId = $this->customer->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(fn () => DB::table('sales_invoices')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $acmeTenantId,
            'company_id' => $acmeCompanyId,
            'customer_id' => $acmeCustomerId,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'currency_code' => 'LKR',
            'total' => '0.0000',
            'amount_due' => '0.0000',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('sales_invoices'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
