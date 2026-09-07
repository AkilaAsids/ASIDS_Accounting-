<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Enums;

/**
 * Where a supplier payment stands (ADR 0020 §C1).
 *
 * Only `Posted` is reachable this wave. `Cancelled` exists from the start even though nothing sets it —
 * mirroring how `BillStatus` shipped all five states before four were reachable (`BillStatus.php:18-24`): the
 * database status CHECK covers both, so the cancellation slice adds behaviour rather than a CHECK-widening
 * migration (Gate-1 dec. 8).
 *
 * There is no `Draft`: a payment records cash that has already moved, so it is recorded and posted in one step
 * (Gate-1 dec. 5). A payment is never editable, so — unlike `BillStatus` — there is no `isEditable()`.
 */
enum PaymentStatus: string
{
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }
}
