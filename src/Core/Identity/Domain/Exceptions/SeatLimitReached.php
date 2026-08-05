<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The workspace has no licensed seat available.
 *
 * A 402 rather than a 422: the fix is commercial, and the status lets the SPA route
 * the user to the billing screen rather than showing a form error next to a field
 * that is not the problem.
 */
final class SeatLimitReached extends PlatformException
{
    public static function at(int $limit): self
    {
        return new self(
            sprintf('This workspace has reached its limit of %d users. Upgrade your plan or deactivate an unused account.', $limit),
            ['user_limit' => $limit],
        );
    }

    public function problemCode(): string
    {
        return 'seat-limit-reached';
    }

    public function problemTitle(): string
    {
        return 'User limit reached';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_PAYMENT_REQUIRED;
    }
}
