<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hostname or tenant identifier on the request matches no tenant.
 *
 * The response deliberately does not distinguish "no such workspace" from
 * "workspace exists but you may not see it": both are 404, so the endpoint cannot
 * be used to enumerate which companies are ASIDS customers.
 */
final class TenantNotFound extends PlatformException
{
    public static function forHost(string $host): self
    {
        return new self(
            'No workspace is available at this address.',
            ['host' => $host],
        );
    }

    public static function forIdentifier(string $identifier): self
    {
        return new self(
            'No workspace matches the supplied identifier.',
            ['identifier' => $identifier],
        );
    }

    public function problemCode(): string
    {
        return 'workspace-not-found';
    }

    public function problemTitle(): string
    {
        return 'Workspace not found';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
