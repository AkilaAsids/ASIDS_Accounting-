<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Models;

use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A double-entry document.
 *
 * The first production consumer of the `Auditable` trait. Phase 1 shipped that trait with no model
 * applying it, because the business documents it was written for did not exist yet — this is one of
 * them, and every posting, reversal and draft edit now lands in the audit trail with its before and
 * after values.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $journal_id
 * @property string $fiscal_period_id
 * @property string|null $number
 * @property DocumentType $document_type
 * @property string|null $source_type
 * @property string|null $source_id
 * @property CarbonImmutable $entry_date
 * @property string $description
 * @property string|null $reference
 * @property JournalEntryStatus $status
 * @property CarbonImmutable|null $posted_at
 * @property string|null $posted_by_id
 * @property string|null $reverses_entry_id
 * @property string|null $reversed_by_entry_id
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversal_reason
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read Journal $journal
 * @property-read FiscalPeriod $fiscalPeriod
 * @property-read EloquentCollection<int, JournalLine> $lines
 * @property-read Model|null $source
 */
final class JournalEntry extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'journal_entries';

    /** @var list<string> */
    protected $fillable = ['entry_date', 'description', 'reference'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }

    /**
     * The entry's total, taken from the debit side.
     *
     * Either side gives the same answer for a balanced entry, which is the point — a caller reading
     * "the amount" of an entry does not have to know which side to look at.
     */
    public function total(string $currency): Money
    {
        $minorUnits = $this->lines->sum(
            static fn (JournalLine $line): int => $line->debitMoney($currency)->minorUnits,
        );

        return Money::ofMinorUnits((int) $minorUnits, $currency);
    }

    public function isBalanced(): bool
    {
        $debits = $this->lines->sum(static fn (JournalLine $line): int => $line->debit_minor_units);
        $credits = $this->lines->sum(static fn (JournalLine $line): int => $line->credit_minor_units);

        return $debits === $credits;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isPosted(): bool
    {
        return $this->status->isPosted();
    }

    /**
     * The document that caused this entry, if one did.
     *
     * Null for entries made directly — journal vouchers, opening balances, the year-end close. The
     * morph map is enforced platform-wide, so an unmapped alias throws here rather than returning
     * null and letting a broken link read as "no source".
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    /**
     * The source as a value object, or null.
     *
     * Preferred over reading the two columns directly: it cannot represent a half-set pair, which is
     * the shape the database CHECK also refuses.
     */
    public function sourceDocument(): ?SourceDocument
    {
        return SourceDocument::fromColumns($this->source_type, $this->source_id);
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @return Builder<JournalEntry>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Every entry a given document caused, its reversals included.
     *
     * Ordered oldest first, so the original posting precedes the reversal that undoes it — the order
     * an auditor reads a document's history in.
     *
     * `id` breaks the tie, and it is not decoration. A document posted and reversed inside one
     * transaction can carry the same `created_at` to the microsecond, and the two entries then come
     * back in whatever order the planner chose — which showed up as a test that passed alone and
     * failed in a full run. The keys are UUIDv7, so they are time-ordered: the tiebreak is
     * chronological rather than merely stable.
     *
     * @param  Builder<JournalEntry>  $query
     * @return Builder<JournalEntry>
     */
    public function scopeForSource(Builder $query, SourceDocument $source): Builder
    {
        return $query
            ->where('source_type', $source->type)
            ->where('source_id', $source->id)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @return Builder<JournalEntry>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            JournalEntryStatus::Posted->value,
            JournalEntryStatus::Reversed->value,
        ]);
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @return Builder<JournalEntry>
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', JournalEntryStatus::Draft->value);
    }

    /**
     * Columns worth recording a change to.
     *
     * A posted entry is immutable, so in practice the audit trail records its creation, its posting
     * and its reversal. That is exactly the history an auditor asks for.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return ['number', 'entry_date', 'description', 'reference', 'status', 'reversal_reason'];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['accounting', 'ledger'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'entry_date' => 'immutable_date',
            'status' => JournalEntryStatus::class,
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }
}
