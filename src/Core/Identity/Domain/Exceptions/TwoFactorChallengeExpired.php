<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two factor challenge issued at sign-in has expired or was already consumed.
 *
 * Deliberately short lived: the challenge is a bearer credential that proves the
 * password step passed, so a long window would make a leaked challenge as useful
 * as a leaked password.
 */
final class TwoFactorChallengeExpired extends PlatformException
{
    public function __construct()
    {
        parent::__construct('This sign-in attempt has expired. Please enter your e-mail and password again.');
    }

    public function problemCode(): string
    {
        return 'two-factor-challenge-expired';
    }

    public function problemTitle(): string
    {
        return 'Sign-in attempt expired';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_UNAUTHORIZED;
    }
}
