<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

final class InvalidTwoFactorCode extends PlatformException
{
    public function __construct()
    {
        parent::__construct('That verification code is not valid. Codes expire every 30 seconds — check your authenticator app for the current one.');
    }

    public function problemCode(): string
    {
        return 'invalid-two-factor-code';
    }

    public function problemTitle(): string
    {
        return 'Verification failed';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
