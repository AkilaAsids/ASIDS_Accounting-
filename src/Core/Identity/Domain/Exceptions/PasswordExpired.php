<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user is authenticated but their password has aged past the rotation policy.
 *
 * Signalled with 428 Precondition Required, which is the one status meaning
 * "authenticated, but you must do something first" without being confused with a
 * permission failure (403) or an expired session (401). The SPA routes on it to the
 * change-password screen.
 */
final class PasswordExpired extends PlatformException
{
    public function __construct()
    {
        parent::__construct('Your password must be changed before you can continue.');
    }

    public function problemCode(): string
    {
        return 'password-expired';
    }

    public function problemTitle(): string
    {
        return 'Password change required';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_PRECONDITION_REQUIRED;
    }
}
