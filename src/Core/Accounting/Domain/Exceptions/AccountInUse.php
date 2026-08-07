<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * An account that has been used cannot be changed in this way.
 *
 * The reclassification case is the one that matters. An account whose type moves from expense to
 * asset silently rewrites every statement it has ever appeared on: the books still balance, nothing
 * errors, and last year's filed profit simply becomes a different number. There is no way to detect
 * that after the fact from the data alone, which is why it is refused rather than warned about.
 */
final class AccountInUse extends BusinessRuleViolation
{
    public static function cannotChangeType(string $code, string $from, string $to): self
    {
        return new self(
            sprintf(
                '“%s” has journal entries posted to it, so its type cannot change from %s to %s. Create a new account and transfer the balance instead.',
                $code,
                $from,
                $to,
            ),
            'account-type-locked',
            ['code' => $code, 'from' => $from, 'to' => $to],
        );
    }

    public static function cannotDelete(string $code): self
    {
        return new self(
            sprintf('“%s” has journal entries posted to it and cannot be deleted. Archive it instead — its history stays readable.', $code),
            'account-in-use',
            ['code' => $code],
        );
    }

    public static function cannotStopBeingPostable(string $code): self
    {
        return new self(
            sprintf('“%s” already has journal entries, so it cannot become a heading account.', $code),
            'account-has-postings',
            ['code' => $code],
        );
    }

    public static function cannotDeleteWithChildren(string $code): self
    {
        return new self(
            sprintf('“%s” has accounts rolling up into it. Move or delete those first.', $code),
            'account-has-children',
            ['code' => $code],
        );
    }
}
