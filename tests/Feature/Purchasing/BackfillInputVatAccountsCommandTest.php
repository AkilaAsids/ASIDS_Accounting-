<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The `purchasing:backfill-input-vat-accounts` operator command — Stage 8 of Wave 7 (ADR 0019 §D-note (a),
 * Gate-2 fork resolved: build the command).
 *
 * The input-VAT posting refusal (AC-3.7) is the guarantee; this command is the reviewable one-step remedy for
 * the day-one state where most tenants' VAT codes have no `input_account_id`. It is deliberately conservative:
 *
 *   * dry-run by default — it reports and changes nothing without an explicit `--apply`;
 *   * it points a VAT-charging code with no input account at that company's `1170 Input VAT Recoverable` leaf,
 *     and only when exactly one active postable such account exists (else it reports and skips, naming the
 *     company — `input_account_id` is a *setting*, not a system key, so it never guesses);
 *   * idempotent — a second run changes nothing;
 *   * it never overwrites a code that already names an input account.
 *
 * It sweeps every company under `RowLevelSecurity::bypass()` with the `assertBypassEffective()` guard, so a
 * NOBYPASSRLS role on a FORCED table cannot silently touch zero rows.
 *
 * RED expectation before Stage 8 lands: the command is not registered, so `artisan(...)` raises
 * `CommandNotFoundException` and every test errors.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->inputVat = Account::query()->forCompany($this->company->getKey())->where('code', '1170')->firstOrFail();
    $this->outputVat = Account::query()->forCompany($this->company->getKey())->where('code', '2140')->firstOrFail();

    // A charging VAT code with no input account — the day-one state the command exists to remedy.
    $this->code = chargingCodeWithoutInput($this->company, 'VAT', (string) $this->outputVat->getKey());
});

/**
 * A VAT-charging code (rate 18%) with an output account (required for a charging code) and no input account.
 */
function chargingCodeWithoutInput(object $company, string $code, string $outputAccountId): TaxCode
{
    return app(TaxCodeService::class)->create($company, new TaxCodeData(
        code: $code,
        name: $code.' tax',
        taxType: TaxType::Vat,
        rate: '18',
        effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        outputAccountId: $outputAccountId,
        inputAccountId: null,
    ));
}

function freshInputAccountId(TaxCode $code): ?string
{
    $value = TaxCode::query()->whereKey($code->getKey())->value('input_account_id');

    return $value === null ? null : (string) $value;
}

describe('the dry run', function (): void {
    it('is the default, and changes nothing', function (): void {
        $this->artisan('purchasing:backfill-input-vat-accounts')->assertSuccessful();

        // No `--apply`: the code still has no input account.
        expect(freshInputAccountId($this->code))->toBeNull();
    });
});

describe('applying', function (): void {
    it('points an eligible code at the company’s 1170 Input VAT Recoverable', function (): void {
        $this->artisan('purchasing:backfill-input-vat-accounts', ['--apply' => true])->assertSuccessful();

        expect(freshInputAccountId($this->code))->toBe((string) $this->inputVat->getKey());
    });

    it('is idempotent', function (): void {
        $this->artisan('purchasing:backfill-input-vat-accounts', ['--apply' => true])->assertSuccessful();
        $afterFirst = freshInputAccountId($this->code);

        $this->artisan('purchasing:backfill-input-vat-accounts', ['--apply' => true])->assertSuccessful();

        // A second run finds nothing eligible and leaves the account exactly where the first put it.
        expect(freshInputAccountId($this->code))->toBe($afterFirst)
            ->and($afterFirst)->toBe((string) $this->inputVat->getKey());
    });

    it('never overwrites a code that already names an input account', function (): void {
        // A company that configured its own input account ahead of the purchasing phase. The command respects it.
        $prepayments = Account::query()->forCompany($this->company->getKey())->where('code', '1160')->firstOrFail();

        $configured = app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'VAT-SET',
            name: 'Configured VAT',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
            inputAccountId: (string) $prepayments->getKey(),
        ));

        $this->artisan('purchasing:backfill-input-vat-accounts', ['--apply' => true])->assertSuccessful();

        // The already-set code keeps its own account; only the null one is filled.
        expect(freshInputAccountId($configured))->toBe((string) $prepayments->getKey())
            ->and(freshInputAccountId($this->code))->toBe((string) $this->inputVat->getKey());
    });

    it('leaves a non-charging code alone', function (): void {
        // Zero-rated: it charges nothing, so there is no recoverable input tax and nothing to point anywhere.
        $zero = app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        $this->artisan('purchasing:backfill-input-vat-accounts', ['--apply' => true])->assertSuccessful();

        expect(freshInputAccountId($zero))->toBeNull();
    });
});

describe('a company without a unique input VAT account', function (): void {
    it('is skipped, and its charging code is left untouched', function (): void {
        // A second company whose 1170 has been removed — so the command cannot know which account is the input
        // VAT one, and refuses to guess (it reports and skips, naming the company; §D-note (a)).
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $secondOutput = Account::query()->forCompany((string) $second->getKey())->where('code', '2140')->firstOrFail();
        $secondCode = chargingCodeWithoutInput($second, 'VAT', (string) $secondOutput->getKey());

        DB::table('accounts')
            ->where('company_id', $second->getKey())
            ->where('code', '1170')
            ->update(['deleted_at' => now()]);

        $this->artisan('purchasing:backfill-input-vat-accounts', ['--apply' => true])->assertSuccessful();

        // The ambiguous company is untouched; the well-formed one is still filled.
        expect(freshInputAccountId($secondCode))->toBeNull()
            ->and(freshInputAccountId($this->code))->toBe((string) $this->inputVat->getKey());
    });
});
