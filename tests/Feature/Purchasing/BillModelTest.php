<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\BillLine;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The `Bill`/`BillLine` models, `BillStatus`, and `DocumentType::Bill` — Stage 3 of Wave 7 (ADR 0019 §B, §C).
 *
 * The payable-side mirror of `SalesInvoice`/`SalesInvoiceLine` and `SalesInvoiceStatus`. Where the invoice
 * domain made a decision the bill domain makes the identical one; the deliberate divergences are the payable
 * vocabulary (`Posted`, not `Issued`; `hasBeenPosted`; `scopeOutstanding`; `expenseAccount`) and the first
 * non-gapless `DocumentType` case.
 *
 * RED expectation before Stage 3 lands: `BillStatus`, `Bill`, `BillLine` and `DocumentType::Bill` do not exist,
 * and the morph alias is unregistered — so every test errors on a missing class/case or the null morph map.
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

/**
 * Drives a freshly-made draft bill to the given status with raw SQL. The immutability trigger's
 * `WHEN (OLD.status <> 'draft')` clause means the transition *out* of draft is never caught, so this one update
 * may set status, number and posted_at together — exactly the property `post()` depends on.
 */
function billAtStatus(string $status, string $number, string $invoiceNumber): Bill
{
    $bill = Bill::factory()->create([
        ...test()->references,
        'supplier_invoice_number' => $invoiceNumber,
    ]);

    if ($status !== BillStatus::Draft->value) {
        DB::table('bills')->where('id', $bill->getKey())->update([
            'status' => $status,
            'number' => $number,
            'posted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $bill->refresh();
}

describe('the BillStatus enum', function (): void {
    it('declares all five cases, only draft and posted reachable this wave', function (): void {
        expect(BillStatus::Draft->value)->toBe('draft')
            ->and(BillStatus::Posted->value)->toBe('posted')
            ->and(BillStatus::PartiallyPaid->value)->toBe('partially_paid')
            ->and(BillStatus::Paid->value)->toBe('paid')
            ->and(BillStatus::Cancelled->value)->toBe('cancelled');
    });

    it('is editable only while a draft', function (): void {
        expect(BillStatus::Draft->isEditable())->toBeTrue()
            ->and(BillStatus::Posted->isEditable())->toBeFalse()
            ->and(BillStatus::PartiallyPaid->isEditable())->toBeFalse()
            ->and(BillStatus::Paid->isEditable())->toBeFalse()
            ->and(BillStatus::Cancelled->isEditable())->toBeFalse();
    });

    it('has been posted for everything but a draft', function (): void {
        // Mirror `hasBeenIssued()`: a cancelled bill *was* posted, keeps its number and its ledger entry.
        expect(BillStatus::Draft->hasBeenPosted())->toBeFalse()
            ->and(BillStatus::Posted->hasBeenPosted())->toBeTrue()
            ->and(BillStatus::PartiallyPaid->hasBeenPosted())->toBeTrue()
            ->and(BillStatus::Paid->hasBeenPosted())->toBeTrue()
            ->and(BillStatus::Cancelled->hasBeenPosted())->toBeTrue();
    });

    it('is outstanding only while posted or partially paid', function (): void {
        // Mirror `isCollectable()`: the probe's source of truth (§C2, §E). Cancelled cleared, paid settled,
        // draft not yet owed.
        expect(BillStatus::Posted->isOutstanding())->toBeTrue()
            ->and(BillStatus::PartiallyPaid->isOutstanding())->toBeTrue()
            ->and(BillStatus::Draft->isOutstanding())->toBeFalse()
            ->and(BillStatus::Paid->isOutstanding())->toBeFalse()
            ->and(BillStatus::Cancelled->isOutstanding())->toBeFalse();
    });

    it('labels each case', function (): void {
        expect(BillStatus::Draft->label())->toBe('Draft')
            ->and(BillStatus::Posted->label())->toBe('Posted');
    });
});

describe('DocumentType::Bill', function (): void {
    it('carries the BILL prefix and does not require gapless numbering', function (): void {
        // The first non-gapless case (ADR §B, Gate-1 dec. 1): a bill is received, not issued, so no authority
        // audits *our* internal bill numbers for completeness.
        expect(DocumentType::Bill->value)->toBe('bill')
            ->and(DocumentType::Bill->prefix())->toBe('BILL')
            ->and(DocumentType::Bill->label())->toBe('Bill')
            ->and(DocumentType::Bill->requiresGaplessNumbering())->toBeFalse();
    });

    it('leaves every existing family gapless', function (): void {
        // The change to the enum is additive, and the gaplessness of the documents an authority audits is
        // unchanged — the exact regression a shared branch would introduce.
        expect(DocumentType::JournalVoucher->requiresGaplessNumbering())->toBeTrue()
            ->and(DocumentType::OpeningBalance->requiresGaplessNumbering())->toBeTrue()
            ->and(DocumentType::YearEndClose->requiresGaplessNumbering())->toBeTrue()
            ->and(DocumentType::SalesInvoice->requiresGaplessNumbering())->toBeTrue();
    });

    it('adds exactly one case', function (): void {
        expect(DocumentType::cases())->toHaveCount(5);
    });
});

describe('the Bill model', function (): void {
    it('casts status, dates and money', function (): void {
        $bill = billAtStatus(BillStatus::Posted->value, 'BILL-2026-06-0001', 'CAST-1');

        expect($bill->status)->toBeInstanceOf(BillStatus::class)
            ->and($bill->status)->toBe(BillStatus::Posted)
            ->and($bill->bill_date)->toBeInstanceOf(CarbonImmutable::class)
            ->and($bill->due_date)->toBeInstanceOf(CarbonImmutable::class)
            ->and($bill->posted_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and($bill->total)->toBe('0.0000');
    });

    it('exposes the payable morph alias and round-trips through the enforced map', function (): void {
        // `Bill` is `Auditable`; an audit entry for an unmapped class throws, so the alias must be registered in
        // the provider boot (§E).
        expect(Bill::MORPH_ALIAS)->toBe('bill')
            ->and(Relation::getMorphedModel(Bill::MORPH_ALIAS))->toBe(Bill::class);
    });

    it('is draft and editable only while a draft', function (): void {
        $draft = Bill::factory()->create($this->references);
        $posted = billAtStatus(BillStatus::Posted->value, 'BILL-2026-06-0002', 'EDIT-1');

        expect($draft->isDraft())->toBeTrue()
            ->and($draft->isEditable())->toBeTrue()
            ->and($posted->isDraft())->toBeFalse()
            ->and($posted->isEditable())->toBeFalse();
    });

    it('never reports a draft as overdue but reports a posted past-due bill', function (): void {
        // Derived, never stored — mirror `SalesInvoice::isOverdue()`.
        $draft = Bill::factory()->on('2026-01-01', '2026-01-15')->create($this->references);
        $posted = billAtStatus(BillStatus::Posted->value, 'BILL-2026-06-0003', 'OVER-1');

        expect($draft->isOverdue(CarbonImmutable::parse('2027-01-01')))->toBeFalse()
            ->and($posted->isOverdue(CarbonImmutable::parse('2027-01-01')))->toBeTrue()
            ->and($posted->isOverdue(CarbonImmutable::parse('2020-01-01')))->toBeFalse();
    });

    it('scopes drafts, a company, and outstanding bills', function (): void {
        Bill::factory()->create([...$this->references, 'supplier_invoice_number' => 'SCOPE-DRAFT']);
        billAtStatus(BillStatus::Posted->value, 'BILL-S1', 'SCOPE-POSTED');
        billAtStatus(BillStatus::PartiallyPaid->value, 'BILL-S2', 'SCOPE-PARTIAL');
        billAtStatus(BillStatus::Paid->value, 'BILL-S3', 'SCOPE-PAID');
        billAtStatus(BillStatus::Cancelled->value, 'BILL-S4', 'SCOPE-CANCELLED');

        expect(Bill::query()->forCompany($this->company->getKey())->count())->toBe(5)
            ->and(Bill::query()->forCompany($this->company->getKey())->drafts()->count())->toBe(1)
            // Outstanding = posted + partially_paid; excludes draft, paid, cancelled (§C2).
            ->and(Bill::query()->forCompany($this->company->getKey())->outstanding()->count())->toBe(2);
    });

    it('orders its lines by line number', function (): void {
        $bill = Bill::factory()->create($this->references);
        BillLine::factory()->atPosition(2)->create(['bill_id' => $bill->getKey(), 'company_id' => $this->company->getKey(), 'expense_account_id' => $this->expense->getKey()]);
        BillLine::factory()->atPosition(1)->create(['bill_id' => $bill->getKey(), 'company_id' => $this->company->getKey(), 'expense_account_id' => $this->expense->getKey()]);

        expect($bill->fresh()->lines->pluck('line_number')->all())->toBe([1, 2]);
    });
});

describe('the Bill fillable and audit surface', function (): void {
    it('only lets the free-text fields be mass-assigned', function (): void {
        // Every figure and identifier is service-controlled — a fillable `supplier_invoice_number` or `number`
        // would let a caller write one the guards then refuse with a constraint name (mirror `SalesInvoice`).
        $fillable = (new Bill)->getFillable();

        expect($fillable)->toBe(['notes', 'terms'])
            ->and($fillable)->not->toContain('supplier_invoice_number')
            ->and($fillable)->not->toContain('number')
            ->and($fillable)->not->toContain('status')
            ->and($fillable)->not->toContain('total');
    });

    it('audits the change-worthy columns, including the supplier invoice number', function (): void {
        $auditOnly = (new Bill)->auditOnly();

        expect($auditOnly)->toContain('number')
            ->and($auditOnly)->toContain('supplier_invoice_number')
            ->and($auditOnly)->toContain('supplier_id')
            ->and($auditOnly)->toContain('bill_date')
            ->and($auditOnly)->toContain('due_date')
            ->and($auditOnly)->toContain('subtotal')
            ->and($auditOnly)->toContain('discount_total')
            ->and($auditOnly)->toContain('tax_total')
            ->and($auditOnly)->toContain('total')
            ->and($auditOnly)->toContain('status');
    });

    it('tags audit entries for the purchasing bill domain', function (): void {
        expect((new Bill)->auditTags())->toBe(['purchasing', 'bill']);
    });
});

describe('the BillLine model', function (): void {
    it('has no morph alias and is not independently mapped', function (): void {
        // A line is never audited separately and can never be a source document, so it registers no alias —
        // registering one would claim something may point at it (mirror `SalesInvoiceLine`, decision B6).
        expect(defined(BillLine::class.'::MORPH_ALIAS'))->toBeFalse()
            ->and(Relation::getMorphedModel('bill_line'))->toBeNull();
    });

    it('mass-assigns nothing', function (): void {
        // Every value on a line is computed or validated by the service.
        expect((new BillLine)->getFillable())->toBe([]);
    });

    it('relates a line to its expense account, not a revenue account', function (): void {
        $bill = Bill::factory()->create($this->references);
        $line = BillLine::factory()->create([
            'bill_id' => $bill->getKey(),
            'company_id' => $this->company->getKey(),
            'expense_account_id' => $this->expense->getKey(),
        ]);

        expect($line->expenseAccount)->not->toBeNull()
            ->and($line->expenseAccount->code)->toBe('5100')
            ->and($line->bill->getKey())->toBe($bill->getKey());
    });
});
