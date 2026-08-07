<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Enums;

/**
 * Document families that carry their own number sequence.
 *
 * Each family numbers independently — a journal voucher and a future invoice do not share a counter
 * — because the statutory requirements differ. Sri Lankan e-invoicing will demand gapless invoice
 * numbering; journal vouchers merely need to be unique and orderly.
 *
 * Only the journal families exist in this phase. Sales and purchase documents arrive with their own
 * phases and add cases here.
 */
enum DocumentType: string
{
    case JournalVoucher = 'journal_voucher';
    case OpeningBalance = 'opening_balance';
    case YearEndClose = 'year_end_close';

    public function label(): string
    {
        return match ($this) {
            self::JournalVoucher => 'Journal voucher',
            self::OpeningBalance => 'Opening balance',
            self::YearEndClose => 'Year-end close',
        };
    }

    /**
     * The prefix a number carries, so a document's family is readable from its identifier alone.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::JournalVoucher => 'JV',
            self::OpeningBalance => 'OB',
            self::YearEndClose => 'YEC',
        };
    }

    /**
     * Whether the sequence must have no gaps.
     *
     * Gapless numbering costs a row lock per document, serialising issuance within a company. It is
     * worth that for anything a tax authority may audit for completeness, and not worth it otherwise.
     * Journal vouchers are auditable, so all three currently qualify — the distinction exists because
     * later phases will add internal document types where it does not.
     */
    public function requiresGaplessNumbering(): bool
    {
        return true;
    }
}
