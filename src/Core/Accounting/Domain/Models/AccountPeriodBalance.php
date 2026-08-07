<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Models;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's movements within one period.
 *
 * Derived from `journal_lines` and maintained inside the posting transaction. Never authoritative:
 * if this and the lines disagree, the lines are right and this row is drift.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $account_id
 * @property string $fiscal_period_id
 * @property string $debit_total
 * @property string $credit_total
 * @property int $line_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 * @property-read FiscalPeriod $fiscalPeriod
 */
final class AccountPeriodBalance extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'account_period_balances';

    /** @var list<string> */
    protected $fillable = ['debit_total', 'credit_total', 'line_count'];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function debits(string $currency): Money
    {
        return Money::of((string) $this->debit_total, $currency);
    }

    public function credits(string $currency): Money
    {
        return Money::of((string) $this->credit_total, $currency);
    }

    /**
     * The period's movement in the account's own direction.
     *
     * Signed by the account's normal balance, so an asset and a liability both read positive when
     * they moved the way their type expects.
     */
    public function movement(Account $account, string $currency): Money
    {
        return Money::ofMinorUnits(
            $account->normal_balance->signedFrom(
                $this->debits($currency)->minorUnits,
                $this->credits($currency)->minorUnits,
            ),
            $currency,
        );
    }

    /**
     * @param  Builder<AccountPeriodBalance>  $query
     * @return Builder<AccountPeriodBalance>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<AccountPeriodBalance>  $query
     * @return Builder<AccountPeriodBalance>
     */
    public function scopeForPeriod(Builder $query, string $periodId): Builder
    {
        return $query->where('fiscal_period_id', $periodId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_total' => 'decimal:4',
            'credit_total' => 'decimal:4',
            'line_count' => 'integer',
        ];
    }
}
