<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write would have created or moved a record into a tenant other than the
 * active one.
 *
 * This is always a bug, never user input, so the message given to the client is
 * generic while the tenant identifiers are kept in the exception context for the
 * log. Telling a caller which tenant they touched would confirm that tenant's
 * existence.
 */
final class CrossTenantWriteAttempted extends PlatformException
{
    public function __construct(string $model, string $from, string $to)
    {
        parent::__construct(
            'This operation is not permitted.',
            [
                'model' => $model,
                'active_tenant_id' => $from,
                'attempted_tenant_id' => $to,
            ],
        );
    }

    public function problemCode(): string
    {
        return 'cross-tenant-write';
    }

    public function problemTitle(): string
    {
        return 'Operation not permitted';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }

    /**
     * The tenant identifiers must not reach the client.
     *
     * @return array<string, mixed>
     */
    public function problemExtensions(): array
    {
        return [];
    }
}
