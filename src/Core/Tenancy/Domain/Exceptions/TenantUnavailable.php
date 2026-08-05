<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Asids\Core\Tenancy\Domain\Enums\TenantStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant exists but is not currently serving requests: still provisioning,
 * suspended for non-payment, or closed.
 *
 * A distinct status (403 with a `workspace_status` member) rather than a 404, so
 * the SPA can render an accurate screen — "your workspace is suspended, contact
 * billing" is actionable, "not found" is not.
 */
final class TenantUnavailable extends PlatformException
{
    public static function because(TenantStatus $status): self
    {
        return new self(
            $status->accessDeniedReason() ?? 'This workspace is unavailable.',
            ['workspace_status' => $status->value],
        );
    }

    public function problemCode(): string
    {
        return 'workspace-unavailable';
    }

    public function problemTitle(): string
    {
        return 'Workspace unavailable';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
