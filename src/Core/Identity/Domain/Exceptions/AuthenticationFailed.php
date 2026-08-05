<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The supplied credentials did not authenticate.
 *
 * One message for every cause — unknown address, wrong password, wrong workspace.
 * Distinguishing them would turn the endpoint into an account enumeration oracle,
 * which is the single most common way an SME's user list leaks.
 */
final class AuthenticationFailed extends PlatformException
{
    public function __construct()
    {
        parent::__construct('The e-mail address or password is incorrect.');
    }

    public function problemCode(): string
    {
        return 'authentication-failed';
    }

    public function problemTitle(): string
    {
        return 'Sign-in failed';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_UNAUTHORIZED;
    }
}
