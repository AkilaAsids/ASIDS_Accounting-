<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Exceptions;

use Asids\Core\Platform\Domain\Contracts\ProblemDetails;
use RuntimeException;

/**
 * Base class for every exception the platform raises deliberately.
 *
 * "Deliberately" is the distinction that matters. A PlatformException is a
 * documented outcome — a rule was violated, a resource was gone, a state
 * transition was illegal — and it is reported to the client as such. Anything
 * that is *not* a PlatformException is a bug, is logged with a stack trace, and
 * is reported to the client as an opaque 500.
 */
abstract class PlatformException extends RuntimeException implements ProblemDetails
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        protected readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function problemType(): string
    {
        return 'https://docs.asidstech.com/errors/'.$this->problemCode();
    }

    public function problemDetail(): string
    {
        return $this->getMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function problemExtensions(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Kebab-case identifier used in the problem type URI and in log searches.
     */
    abstract public function problemCode(): string;
}
