<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Too many failed attempts; the account is temporarily locked.
 *
 * The remaining duration IS disclosed, unlike the reason for a failed password.
 * Withholding it produces support tickets and teaches users nothing, and an
 * attacker who triggered the lockout already knows it happened.
 */
final class AccountLocked extends PlatformException
{
    public static function until(CarbonInterface $until): self
    {
        $seconds = max(1, $until->diffInSeconds(now(), absolute: true));

        return new self(
            sprintf(
                'This account is temporarily locked after too many failed sign-in attempts. Try again in %d minute(s).',
                (int) ceil($seconds / 60)
            ),
            ['locked_until' => $until->toIso8601String(), 'retry_after_seconds' => $seconds],
        );
    }

    public function problemCode(): string
    {
        return 'account-locked';
    }

    public function problemTitle(): string
    {
        return 'Account temporarily locked';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_LOCKED;
    }
}
