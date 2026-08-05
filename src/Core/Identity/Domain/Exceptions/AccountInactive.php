<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The credentials were correct but the account may not sign in.
 *
 * Reached only after the password has been verified, so revealing the account
 * state here discloses nothing to someone who does not already hold the password.
 */
final class AccountInactive extends PlatformException
{
    public static function because(UserStatus $status): self
    {
        return new self(
            $status->signInDeniedReason() ?? 'This account cannot sign in.',
            ['account_status' => $status->value],
        );
    }

    public function problemCode(): string
    {
        return 'account-inactive';
    }

    public function problemTitle(): string
    {
        return 'Account unavailable';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
