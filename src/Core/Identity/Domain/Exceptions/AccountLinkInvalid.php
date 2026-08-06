<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Identity\Application\Services\AccountLinkService;
use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An invitation or password reset link is no longer valid.
 *
 * One message covers expiry, reuse and tampering. That is deliberate: telling a caller
 * which of the three occurred would confirm that the link was once genuine, and therefore
 * that the account exists.
 */
final class AccountLinkInvalid extends PlatformException
{
    public function __construct(private readonly string $purpose)
    {
        parent::__construct(match ($purpose) {
            AccountLinkService::PURPOSE_INVITATION => 'This invitation link is no longer valid. It may have expired or already been used — ask an administrator to send a new one.',
            default => 'This password reset link is no longer valid. It may have expired or already been used — request a new one.',
        });
    }

    public function problemCode(): string
    {
        return $this->purpose === AccountLinkService::PURPOSE_INVITATION
            ? 'invitation-link-invalid'
            : 'password-reset-link-invalid';
    }

    public function problemTitle(): string
    {
        return 'Link no longer valid';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_GONE;
    }
}
