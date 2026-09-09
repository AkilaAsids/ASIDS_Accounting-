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
    case Bill = 'bill';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::JournalVoucher => 'Journal voucher',
            self::OpeningBalance => 'Opening balance',
            self::YearEndClose => 'Year-end close',
            self::SalesInvoice => 'Sales invoice',
            self::Bill => 'Bill',
            self::Payment => 'Payment',
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
            self::Bill => 'BILL',
            self::Payment => 'PAY',
        };
    }

    /**
     * Whether the sequence must have no gaps.
     *
     * Gapless numbering costs a row lock per document, serialising issuance within a company. It is
     * worth that for anything a tax authority may audit for completeness, and not worth it otherwise.
     * The journal and sales families are all auditable — sales invoices most of all, since Sri Lankan
     * e-invoicing demands completeness — so they qualify. `Bill` is the sole case that does not: a bill
     * is *received*, not issued, so no authority audits *our* internal bill numbers for completeness, and
     * the per-document row lock buys nothing (ADR 0019 §B, Gate-1 dec. 1).
     *
     * `Payment` sits on the other side of that line, with the *issued* documents: a payment is *originated*
     * by us, disbursement completeness is a real internal control and audit concern, and its sequence lock
     * is already inside the record-and-post transaction (acquired after the bill locks, so it cannot
     * deadlock). So it is gapless, and the body needs no change — `$this !== self::Bill` already yields
     * `true` for it, leaving `Bill` the single documented exception (ADR 0020 §D, Gate-2 GAPLESS).
     */
    public function requiresGaplessNumbering(): bool
    {
        return $this !== self::Bill;
    }
}
