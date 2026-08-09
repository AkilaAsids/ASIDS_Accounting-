<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\DTOs;

use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Carbon\CarbonImmutable;

/**
 * A whole entry, as submitted.
 *
 * @property-read list<JournalLineData> $lines
 */
final readonly class JournalEntryData
{
    /**
     * @param  list<JournalLineData>  $lines
     */
    public function __construct(
        public CarbonImmutable $entryDate,
        public string $description,
        public array $lines,
        public ?string $reference = null,
        public ?string $journalId = null,
        public DocumentType $documentType = DocumentType::JournalVoucher,
        /**
         * Set when this entry is the reversal of another.
         *
         * Carried on the draft rather than stamped after posting, because the immutability trigger
         * correctly refuses to let a posted entry's `reverses_entry_id` change — the link is part of
         * what the entry *is*, not an annotation added later.
         */
        public ?string $reversesEntryId = null,
        /**
         * The document that caused this entry, when one did.
         *
         * On the draft for the same reason as `reversesEntryId`: the immutability trigger refuses to
         * let a posted entry's source change, and rightly — an entry that could be reattributed to a
         * different invoice after posting would undo the point of an append-only ledger. Null for
         * entries made directly, which is every entry the interactive journal screen produces.
         */
        public ?SourceDocument $source = null,
    ) {}

    /**
     * Built from a validated HTTP payload.
     *
     * Neither `reversesEntryId` nor `source` is read from the request, and that is deliberate. Both
     * assert that an entry was caused by something else, and a client that could set them would be
     * able to claim its hand-typed entry was produced by an invoice — occupying that invoice's slot
     * in the uniqueness index and blocking the real posting. They are set by the services that
     * genuinely know, and only there.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes, string $currency): self
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $attributes['lines'] ?? [];

        return new self(
            entryDate: CarbonImmutable::parse((string) $attributes['entry_date']),
            description: (string) $attributes['description'],
            lines: array_map(
                static fn (array $line): JournalLineData => JournalLineData::fromArray($line, $currency),
                array_values($lines),
            ),
            reference: isset($attributes['reference']) ? (string) $attributes['reference'] : null,
            journalId: isset($attributes['journal_id']) ? (string) $attributes['journal_id'] : null,
        );
    }
}
