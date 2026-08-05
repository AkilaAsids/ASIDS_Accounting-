<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

final class PasswordPreviouslyUsed extends PlatformException
{
    public static function withinLast(int $count): self
    {
        return new self(
            sprintf('This password was used recently. Choose one you have not used in your last %d passwords.', $count),
            ['history_length' => $count],
        );
    }

    public function problemCode(): string
    {
        return 'password-previously-used';
    }

    public function problemTitle(): string
    {
        return 'Password rejected';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
