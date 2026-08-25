<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Enums;

/**
 * How money reached the company on a receipt.
 *
 * A closed set rather than free text, because the method is a fact an auditor reconciles against a bank
 * statement — "cheque" and "bank transfer" clear differently, and a typo'd free-text value cannot be grouped.
 * Backed by the `customer_receipts_payment_method` CHECK, which lists exactly these four values, so a value the
 * enum does not know is refused at the database as well as by the type.
 *
 * This is not a bank-account entity: it says *how* the money arrived, not *which* account it landed in — that
 * is `customer_receipts.bank_account_id`, an ordinary GL asset account (Gate-1 #3). A real bank-account domain
 * (statement import, reconciliation) is the deferred Banking phase.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::Card => 'Card',
        };
    }
}
