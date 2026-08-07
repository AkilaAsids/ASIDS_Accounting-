<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * An entry names an account that cannot receive postings.
 *
 * Three distinct causes, each with its own remedy, so they are reported separately rather than as
 * one generic refusal.
 */
final class AccountNotPostable extends BusinessRuleViolation
{
    public static function isHeading(string $code): self
    {
        return new self(
            sprintf('“%s” is a heading account used to group others. Post to one of the accounts beneath it.', $code),
            'account-not-postable',
            ['code' => $code],
        );
    }

    public static function isArchived(string $code): self
    {
        return new self(
            sprintf('“%s” has been archived and no longer accepts entries.', $code),
            'account-archived',
            ['code' => $code],
        );
    }

    public static function foreignCompany(): self
    {
        return new self(
            'An entry cannot post to an account belonging to a different company.',
            'account-foreign-company',
        );
    }
}
