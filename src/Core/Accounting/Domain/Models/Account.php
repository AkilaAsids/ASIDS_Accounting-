<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Models;

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Enums\FinancialStatement;
use Asids\Core\Accounting\Domain\Enums\NormalBalance;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An account in a company's chart.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property AccountType $type
 * @property NormalBalance $normal_balance
 * @property bool $is_postable
 * @property bool $is_system
 * @property string|null $system_key
 * @property bool $is_active
 * @property CarbonImmutable|null $archived_at
 * @property string|null $template_version
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Company $company
 * @property-read Account|null $parent
 * @property-read EloquentCollection<int, Account> $children
 */
final class Account extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    /**
     * System accounts the platform resolves by name rather than by code.
     *
     * A customer may renumber their whole chart — many do, to match a group standard — and the
     * year-end close still has to find retained earnings afterwards. Looking it up by code would
     * break the moment someone renumbered; the key is the stable handle.
     */
    public const string RETAINED_EARNINGS = 'retained_earnings';

    public const string OPENING_BALANCE_EQUITY = 'opening_balance_equity';

    /**
     * Where a customer invoice's debit lands when the customer names no account of its own.
     *
     * The great majority name none — segmenting receivables per customer is unusual — so this is the
     * account almost every sales posting debits. It exists as a key rather than as a code for the reason
     * above: a company that renumbers its chart must still be invoiceable afterwards.
     */
    public const string TRADE_RECEIVABLES = 'trade_receivables';

    protected $table = 'accounts';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description', 'sort_order'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function statement(): FinancialStatement
    {
        return $this->type->statement();
    }

    public function isPermanent(): bool
    {
        return $this->type->isPermanent();
    }

    /**
     * Whether an entry may be posted to this account right now.
     *
     * Both conditions, always asked together: a heading account has no balance of its own, and an
     * archived account is closed to new activity while remaining readable.
     */
    public function acceptsPostings(): bool
    {
        return $this->is_postable && $this->is_active;
    }

    /**
     * The account's ancestors, nearest first.
     *
     * Walked rather than queried recursively because a chart is a handful of levels deep and the
     * caller — the cycle check, and the roll-up on reports — already holds the company's accounts.
     *
     * @param  EloquentCollection<int, Account>  $within
     * @return list<Account>
     */
    public function ancestorsWithin(EloquentCollection $within): array
    {
        $byId = $within->keyBy('id');
        $ancestors = [];
        $current = $this->parent_id;

        // Bounded by the number of accounts, so a cycle that somehow reached the database cannot
        // hang the request — it terminates and the caller sees a repeat.
        $guard = $within->count() + 1;

        while ($current !== null && $guard-- > 0) {
            $parent = $byId->get($current);

            if (! $parent instanceof self) {
                break;
            }

            $ancestors[] = $parent;
            $current = $parent->parent_id;
        }

        return $ancestors;
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true)->where('is_active', true);
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeOfType(Builder $query, AccountType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeWithSystemKey(Builder $query, string $key): Builder
    {
        return $query->where('system_key', $key);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The normal balance is a function of the type and is never set by a caller. Derived on save
        // rather than left to the service, so a console command or a future module cannot produce an
        // account whose sign convention disagrees with its classification. The database has the same
        // rule as a check constraint; this is what stops it ever being reached.
        self::saving(static function (self $account): void {
            $account->normal_balance = $account->type->normalBalance();

            $account->code = trim($account->code);
        });
    }
}
