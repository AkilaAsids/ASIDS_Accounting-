<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\TaxCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tax code and the rate it carried over one date range.
 *
 * One row is one rate for one span of time. `VAT` at 18% until June and 20% from July are two rows
 * sharing a code, because an invoice issued in May must still resolve 18% years later. Overwriting the
 * old row would rewrite history that a filed return depends on.
 *
 * Audited, and this is one of the models the audit trail matters most for. A rate is the number every
 * invoice under it multiplies by; a change made quietly is a change that misstates a return, and "who
 * changed it and from what" is the first question anyone asks afterwards.
 *
 * `rate` IS A PERCENTAGE
 * ---------------------
 * 18.0000 means 18%, at every layer. The column stores it, the cast preserves it as a decimal string,
 * and nothing here converts it to 0.18 — the conversion to a multiplication factor belongs with the
 * arithmetic that uses it, not with persistence. A model that silently exposed a fraction would make
 * every reader of `$taxCode->rate` guess which convention they were holding.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property TaxType $tax_type
 * @property numeric-string $rate
 * @property string|null $output_account_id
 * @property string|null $input_account_id
 * @property bool $is_active
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string|null $notes
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Company $company
 * @property-read Account|null $outputAccount
 * @property-read Account|null $inputAccount
 */
final class TaxCode extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<TaxCodeFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const string MORPH_ALIAS = 'tax_code';

    protected $table = 'tax_codes';

    /** @var list<string> */
    protected $fillable = ['name', 'notes'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Where tax charged under this code posts.
     *
     * Null only for a code that charges nothing — the database refuses a non-zero rate without one.
     *
     * @return BelongsTo<Account, $this>
     */
    public function outputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'output_account_id');
    }

    /**
     * Where recoverable tax on purchases would post.
     *
     * Unused by sales and populated only by a company that has configured it ahead of the purchasing
     * phase. Present so that phase adds behaviour rather than a column to a populated table.
     *
     * @return BelongsTo<Account, $this>
     */
    public function inputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'input_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Whether this row's range covers the given date.
     *
     * Inclusive at both ends, matching the `daterange(..., '[]')` the exclusion constraint uses. The two
     * must agree: a row the database considers to occupy a date but this method does not would be a row
     * that blocks a rate change and then refuses to be found.
     */
    public function coversDate(CarbonImmutable $date): bool
    {
        if ($date->lessThan($this->effective_from->startOfDay())) {
            return false;
        }

        return $this->effective_to === null
            || $date->lessThanOrEqualTo($this->effective_to->endOfDay());
    }

    /**
     * Whether this row is open-ended — the ordinary state of a company's current rate.
     */
    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }

    public function chargesTax(): bool
    {
        return bccomp($this->rate, '0', 4) > 0;
    }

    /**
     * The rate as a multiplication factor: 18.0000 becomes 0.1800000000.
     *
     * The one place the percentage convention is converted, and it is done with `bcdiv` rather than
     * `$rate / 100` because the division has to be exact. In binary floating point 18.0/100 is not
     * 0.18, and the error would survive into every tax amount the ledger stored.
     *
     * Scale 10 is not arbitrary: it is the widest fraction `Money::multipliedBy()` accepts, and it is
     * more than enough — a percentage held to four decimal places needs six to express as a fraction,
     * so the conversion is exact rather than merely close.
     *
     * @return numeric-string
     */
    public function rateFactor(): string
    {
        /** @var numeric-string $factor */
        $factor = bcdiv($this->rate, '100', 10);

        return $factor;
    }

    /**
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rows for one code, newest range first.
     *
     * Case-insensitive, matching the exclusion constraint's `upper(code)`: a lookup that treated `vat`
     * and `VAT` as different codes would miss the very row the database considers a duplicate.
     *
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeWithCode(Builder $query, string $code): Builder
    {
        return $query
            ->whereRaw('upper(code) = ?', [mb_strtoupper(trim($code))])
            ->orderByDesc('effective_from');
    }

    /**
     * Columns worth recording a change to.
     *
     * `rate` and the effective dates are the reason this model is audited at all. The accounts matter
     * too: repointing `output_account_id` changes which liability every future invoice's tax lands in,
     * which is invisible on an invoice and obvious on a balance sheet.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [
            'code',
            'name',
            'tax_type',
            'rate',
            'output_account_id',
            'input_account_id',
            'is_active',
            'effective_from',
            'effective_to',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['sales', 'tax'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_type' => TaxType::class,
            // A percentage held as a decimal string at the ledger's scale. Never a float: a rate
            // multiplied through `Money` has to be exact, and 18.0000 is not representable in binary
            // floating point.
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }
}
