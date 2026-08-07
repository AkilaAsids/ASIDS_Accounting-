<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A system account cannot be removed or reclassified.
 *
 * Retained earnings and opening balance equity are resolved by machine name by the year-end close
 * and the opening balance routine. Deleting one, or moving its type, does not fail at the time —
 * it fails months later when the year is closed and there is nowhere to put net income.
 *
 * Renaming and renumbering are deliberately still allowed: a customer matching a group chart needs
 * to call it "3200 Accumulated Profits", and the machine name is what keeps that safe.
 */
final class SystemAccountIsProtected extends BusinessRuleViolation
{
    public static function cannotDelete(string $code): self
    {
        return new self(
            sprintf('“%s” is a system account the platform depends on and cannot be deleted.', $code),
            'system-account-protected',
            ['code' => $code],
        );
    }

    public static function cannotChangeType(string $code): self
    {
        return new self(
            sprintf('“%s” is a system account and its type cannot be changed.', $code),
            'system-account-protected',
            ['code' => $code],
        );
    }

    public static function cannotArchive(string $code): self
    {
        return new self(
            sprintf('“%s” is a system account and must stay active — the year-end close posts to it.', $code),
            'system-account-protected',
            ['code' => $code],
        );
    }
}
