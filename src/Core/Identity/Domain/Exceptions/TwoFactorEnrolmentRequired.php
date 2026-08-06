<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The workspace mandates two factor authentication and this user has not enrolled.
 *
 * Raised after authentication, not during it: a user who cannot sign in cannot enrol, so they
 * are signed in but confined to the enrolment and sign-out endpoints.
 */
final class TwoFactorEnrolmentRequired extends PlatformException
{
    public function __construct()
    {
        parent::__construct(
            'This workspace requires two factor authentication. Set it up to continue.',
            ['enrolment_endpoint' => '/api/v1/auth/two-factor/enrol'],
        );
    }

    public function problemCode(): string
    {
        return 'two-factor-enrolment-required';
    }

    public function problemTitle(): string
    {
        return 'Two factor setup required';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_PRECONDITION_REQUIRED;
    }
}
