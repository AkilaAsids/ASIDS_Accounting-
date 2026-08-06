<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request named a company the user cannot reach, or the user is a member of none.
 *
 * The two cases carry different statuses on purpose, because the client must react
 * differently:
 *
 *   * A named-but-inaccessible company is **404**. Distinguishing "exists but you may not
 *     see it" from "does not exist" would let a caller enumerate the companies inside a
 *     workspace they only partly belong to.
 *
 *   * A user with no memberships at all is **403**. There is nothing to hide — the user
 *     knows their own access — and the SPA needs to render "ask an administrator for
 *     access" rather than a not-found page.
 */
final class NoCompanyAccess extends PlatformException
{
    private int $status = Response::HTTP_NOT_FOUND;

    private string $code = 'company-not-available';

    public static function toCompany(): self
    {
        return new self('The requested company does not exist, or you do not have access to it.');
    }

    public static function atAll(): self
    {
        $exception = new self(
            'Your account has not been given access to any company in this workspace. Ask an administrator to grant it.'
        );

        $exception->status = Response::HTTP_FORBIDDEN;
        $exception->code = 'no-company-membership';

        return $exception;
    }

    public function problemCode(): string
    {
        return $this->code;
    }

    public function problemTitle(): string
    {
        return 'Company not available';
    }

    public function problemStatus(): int
    {
        return $this->status;
    }
}
