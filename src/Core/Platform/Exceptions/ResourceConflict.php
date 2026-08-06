<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * The request conflicts with the current state of the resource.
 *
 * Reserved for genuine concurrency and uniqueness collisions — a duplicate
 * company code, a stale optimistic-locking version, a second attempt to accept an
 * invitation that was already accepted — where retrying the identical request
 * will not help but a refreshed one might.
 */
final class ResourceConflict extends PlatformException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        private readonly string $problemCode = 'resource-conflict',
        array $context = [],
    ) {
        parent::__construct($message, $context);
    }

    public static function duplicate(string $resource, string $attribute, string $value): self
    {
        return new self(
            message: sprintf('A %s with %s "%s" already exists.', $resource, $attribute, $value),
            problemCode: 'duplicate-resource',
            context: ['resource' => $resource, 'attribute' => $attribute, 'value' => $value],
        );
    }

    public static function staleVersion(string $resource, int $expected, int $actual): self
    {
        return new self(
            message: sprintf(
                'This %s was modified by someone else. Reload it and try again.',
                $resource
            ),
            problemCode: 'stale-version',
            context: ['resource' => $resource, 'expected_version' => $expected, 'actual_version' => $actual],
        );
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }

    public function problemTitle(): string
    {
        return 'Conflict';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
