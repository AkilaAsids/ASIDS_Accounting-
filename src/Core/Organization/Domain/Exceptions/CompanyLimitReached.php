<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The workspace has reached the number of companies its plan allows.
 *
 * A 402 rather than a 422: the fix is commercial, so the SPA routes to the billing
 * screen instead of showing a field error next to a name that is perfectly valid.
 */
final class CompanyLimitReached extends PlatformException
{
    public static function at(int $limit): self
    {
        return new self(
            sprintf('This workspace has reached its limit of %d companies. Upgrade your plan or archive one you no longer use.', $limit),
            ['company_limit' => $limit],
        );
    }

    public function problemCode(): string
    {
        return 'company-limit-reached';
    }

    public function problemTitle(): string
    {
        return 'Company limit reached';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_PAYMENT_REQUIRED;
    }
}
