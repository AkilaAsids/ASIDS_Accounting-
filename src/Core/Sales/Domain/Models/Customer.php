<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Models;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Enums\CustomerStatus;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A party a company invoices.
 *
 * Audited, because who a company sells to and on what terms is exactly the sort of change an auditor
 * asks about — a credit limit raised the week before a large sale, a payment term quietly extended.
 * `Auditable` records the before and after of each.
 *
 * Soft-deleted rather than hard-deleted, and even that is refused by the service once the customer has
 * invoices: an invoice is a statutory record and it names its customer, so the record has to outlive
 * the relationship.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_identification_number
 * @property string|null $vat_registration_number
 * @property bool $is_vat_registered
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $district
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property int $payment_terms_days
 * @property numeric-string|null $credit_limit
 * @property string|null $receivable_account_id
 * @property string|null $notes
 * @property CustomerStatus $status
 * @property CarbonImmutable|null $archived_at
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Company $company
 * @property-read Branch|null $branch
 * @property-read Account|null $receivableAccount
 */
final class Customer extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const string MORPH_ALIAS = 'customer';

    protected $table = 'customers';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'tax_identification_number',
        'vat_registration_number',
        'is_vat_registered',
        'email',
        'phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'district',
        'postal_code',
        'country_code',
        'notes',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The receivable account this customer's invoices debit.
     *
     * Null for the great majority, who use the company's system AR account. Resolving that fallback is
     * the service's job, not this relation's — a null here means "the default", and pretending
     * otherwise would hide which customers were deliberately segmented.
     *
     * @return BelongsTo<Account, $this>
     */
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Whether a new invoice may name this customer.
     */
    public function acceptsNewInvoices(): bool
    {
        return $this->status->acceptsNewInvoices();
    }

    public function isArchived(): bool
    {
        return $this->status === CustomerStatus::Archived;
    }

    /**
     * The due date for an invoice dated as given, from this customer's terms.
     *
     * Here rather than in the invoice because the terms belong to the customer, and an invoice that
     * derived its own due date would have to reach for them anyway.
     */
    public function dueDateFor(CarbonImmutable $invoiceDate): CarbonImmutable
    {
        return $invoiceDate->addDays($this->payment_terms_days);
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Customers a picker should offer — everything but the archived.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CustomerStatus::Active->value,
            CustomerStatus::Inactive->value,
        ]);
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CustomerStatus::Active->value);
    }

    /**
     * Columns worth recording a change to.
     *
     * The credit limit and payment terms are the point. A limit raised days before a large sale is a
     * question an auditor will ask, and the answer has to be in the trail rather than in someone's
     * memory.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [
            'code',
            'name',
            'legal_name',
            'vat_registration_number',
            'is_vat_registered',
            'payment_terms_days',
            'credit_limit',
            'receivable_account_id',
            'status',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['sales', 'customer'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'is_vat_registered' => 'boolean',
            'payment_terms_days' => 'integer',
            // Kept as a decimal string at the ledger's scale. Never a float: a credit limit compared
            // against a receivable balance has to use the same arithmetic the balance does.
            'credit_limit' => 'decimal:4',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
