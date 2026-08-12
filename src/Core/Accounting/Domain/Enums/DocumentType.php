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
 * The journal families arrived with the ledger; `SalesInvoice` was added by Phase 3 Milestone 5, which is
 * the phase this file's original comment anticipated. Purchase documents add their cases the same way.
 */
enum DocumentType: string
{
    case JournalVoucher = 'journal_voucher';
    case OpeningBalance = 'opening_balance';
    case YearEndClose = 'year_end_close';
    case SalesInvoice = 'sales_invoice';

    public function label(): string
    {
        return match ($this) {
            self::JournalVoucher => 'Journal voucher',
            self::OpeningBalance => 'Opening balance',
            self::YearEndClose => 'Year-end close',
            self::SalesInvoice => 'Sales invoice',
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
            self::SalesInvoice => 'INV',
        };
    }

    /**
     * Whether the sequence must have no gaps.
     *
     * Gapless numbering costs a row lock per document, serialising issuance within a company. It is
     * worth that for anything a tax authority may audit for completeness, and not worth it otherwise.
     * Every family so far is auditable, so all four qualify — sales invoices most of all, since Sri Lankan
     * e-invoicing demands completeness. The distinction exists because later phases will add internal
     * document types where it does not.
     */
    public function requiresGaplessNumbering(): bool
    {
        return true;
    }
}
