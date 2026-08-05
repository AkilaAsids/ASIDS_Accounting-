<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Code that requires a tenant ran without one.
 *
 * This is a 400 rather than a 500 because the usual cause is a client calling a
 * tenant endpoint against the central domain, or omitting the `X-Tenant` header —
 * a caller error with an actionable fix.
 */
final class NoActiveTenant extends PlatformException
{
    public function __construct()
    {
        parent::__construct(
            'No workspace was identified for this request. Use your workspace subdomain, or send the X-Tenant header.'
        );
    }

    public function problemCode(): string
    {
        return 'no-active-tenant';
    }

    public function problemTitle(): string
    {
        return 'Workspace not identified';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
