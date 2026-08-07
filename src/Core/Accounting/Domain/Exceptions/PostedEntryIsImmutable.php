<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A posted entry cannot be edited, deleted or posted again.
 *
 * The database refuses these too. This exists to say why, and to say it in terms of the thing the
 * customer should do instead — post a reversing entry — rather than reporting a trigger name.
 *
 * The rule is not caution. An auditor reading the books must see the mistake and its correction; a
 * tidy history in which the mistake never happened is worth less than an honest one, and in a
 * jurisdiction with seven-year retention it is also not permitted.
 */
final class PostedEntryIsImmutable extends BusinessRuleViolation
{
    public static function cannotEdit(string $number): self
    {
        return new self(
            sprintf('Entry %s has been posted and cannot be changed. Post a reversing entry instead.', $number),
            'posted-entry-immutable',
            ['number' => $number],
        );
    }

    public static function cannotDelete(string $number): self
    {
        return new self(
            sprintf('Entry %s has been posted and cannot be deleted. Post a reversing entry instead.', $number),
            'posted-entry-immutable',
            ['number' => $number],
        );
    }

    public static function alreadyPosted(string $number): self
    {
        return new self(
            sprintf('Entry %s has already been posted.', $number),
            'entry-already-posted',
            ['number' => $number],
        );
    }

    public static function alreadyReversed(string $number): self
    {
        return new self(
            sprintf('Entry %s has already been reversed.', $number),
            'entry-already-reversed',
            ['number' => $number],
        );
    }

    public static function cannotReverseDraft(): self
    {
        return new self(
            'A draft entry has not been posted, so there is nothing to reverse. Delete it instead.',
            'cannot-reverse-draft',
        );
    }
}
