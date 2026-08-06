<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A step-up-protected action needs fresh proof of the second factor.
 *
 * 428 Precondition Required rather than 403: the caller is authorised, they simply have to
 * satisfy a precondition first. The SPA routes on this status to raise the code prompt and
 * then replays the original request.
 */
final class TwoFactorConfirmationRequired extends PlatformException
{
    public function __construct()
    {
        parent::__construct(
            'This action needs to be confirmed with your authenticator code.',
            ['confirmation_endpoint' => '/api/v1/auth/two-factor/confirm-session'],
        );
    }

    public function problemCode(): string
    {
        return 'two-factor-confirmation-required';
    }

    public function problemTitle(): string
    {
        return 'Confirmation required';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_PRECONDITION_REQUIRED;
    }
}
