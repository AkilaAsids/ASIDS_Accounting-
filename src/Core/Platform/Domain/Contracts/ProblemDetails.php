<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Domain\Contracts;

/**
 * An exception that knows how it should appear to an API consumer, as RFC 9457
 * (formerly RFC 7807) problem details.
 *
 * Domain code raises meaningful exceptions; only the transport layer decides
 * status codes and wire format. This interface is the seam between the two, and
 * it is what stops HTTP concerns leaking into services.
 */
interface ProblemDetails
{
    /**
     * Stable, documented identifier for this failure mode. Clients branch on
     * this, never on the human-readable title.
     */
    public function problemType(): string;

    public function problemTitle(): string;

    public function problemStatus(): int;

    public function problemDetail(): string;

    /**
     * Additional machine-readable members merged into the problem document.
     *
     * @return array<string, mixed>
     */
    public function problemExtensions(): array;
}
