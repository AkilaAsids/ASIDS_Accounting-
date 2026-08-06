<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * An operation was well formed but is not allowed by a domain rule.
 *
 * This is a 422, not a 400: the request was understood and syntactically valid,
 * and the client cannot fix it by reformatting. "You cannot archive the only
 * active branch of a company" is a business rule violation; a malformed UUID is
 * not.
 */
class BusinessRuleViolation extends PlatformException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        // NOT named `$code`: Exception already declares a non-readonly `$code`, and redeclaring
        // it as readonly is a fatal error — every throw of this class would crash the request.
        private readonly string $problemCode = 'business-rule-violation',
        array $context = [],
    ) {
        parent::__construct($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(string $code, string $message, array $context = []): self
    {
        return new self($message, $code, $context);
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }

    public function problemTitle(): string
    {
        return 'Business rule violation';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
