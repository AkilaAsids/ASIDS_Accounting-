<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Models;

use Asids\Core\Accounting\Domain\Enums\NormalBalance;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit or one credit.
 *
 * The amounts are `numeric(19,4)` in the database and arrive in PHP as decimal strings. They are
 * deliberately *not* cast to float by an Eloquent cast: `decimal:4` returns a string, and anything
 * that turns them into a float here would undo the exactness `Money` exists to provide, one layer
 * below where anyone would think to look for it.
 *
 * Read them through `debitMoney()` / `creditMoney()`, or through the integer accessors when only a
 * comparison is needed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $journal_entry_id
 * @property string $account_id
 * @property string|null $branch_id
 * @property int $line_number
 * @property string $debit
 * @property string $credit
 * @property string|null $description
 * @property string|null $transaction_currency_code
 * @property string|null $transaction_amount
 * @property string|null $exchange_rate
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 * @property-read JournalEntry $journalEntry
 */
final class JournalLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'journal_lines';

    /** @var list<string> */
    protected $fillable = ['line_number', 'debit', 'credit', 'description'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function debitMoney(string $currency): Money
    {
        return Money::of((string) $this->debit, $currency);
    }

    public function creditMoney(string $currency): Money
    {
        return Money::of((string) $this->credit, $currency);
    }

    /**
     * The debit as ten-thousandths, for comparison and summing without a currency in hand.
     *
     * Used by the balance check, where the question is only whether two totals are equal and naming
     * a currency would be ceremony.
     */
    public function getDebitMinorUnitsAttribute(): int
    {
        return Money::of((string) $this->debit, 'XXX')->minorUnits;
    }

    public function getCreditMinorUnitsAttribute(): int
    {
        return Money::of((string) $this->credit, 'XXX')->minorUnits;
    }

    public function isDebit(): bool
    {
        return $this->debit_minor_units > 0;
    }

    public function side(): NormalBalance
    {
        return $this->isDebit() ? NormalBalance::Debit : NormalBalance::Credit;
    }

    /**
     * Whether this line is denominated in something other than the company's base currency.
     *
     * Always false until the FX phase — a check constraint sees to that — but the question is asked
     * in the reporting code already, so that lifting the constraint changes behaviour rather than
     * requiring the readers to be rewritten.
     */
    public function isForeignCurrency(): bool
    {
        return $this->transaction_currency_code !== null;
    }

    /**
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function scopeForAccount(Builder $query, string $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Lines belonging to entries that affect balances — posted or reversed, never draft.
     *
     * A reversed entry still counts: it and its reversal both remain in the ledger and cancel.
     * Excluding either would leave the trial balance out by the entry's amount.
     *
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function scopeAffectingBalances(Builder $query): Builder
    {
        // A subquery rather than `whereHas`, for two reasons. It reuses `JournalEntry::scopePosted()`
        // instead of restating which statuses count — the two drifting apart is how a reversed entry
        // silently stops being counted — and it reads as the semi-join it is against an indexed key.
        return $query->whereIn(
            'journal_entry_id',
            JournalEntry::query()->posted()->select('id'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `decimal:4` returns a string, never a float. That is the whole reason it is here: an
        // implicit float cast on a monetary column would reintroduce the imprecision the Money value
        // object exists to prevent.
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'line_number' => 'integer',
            'transaction_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
        ];
    }
}
