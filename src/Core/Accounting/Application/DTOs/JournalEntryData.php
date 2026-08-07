<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\DTOs;

use Asids\Core\Accounting\Domain\Enums\DocumentType;
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
    ) {}

    /**
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
