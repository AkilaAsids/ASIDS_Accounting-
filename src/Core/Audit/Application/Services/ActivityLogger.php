<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Application\Services;

use Asids\Core\Audit\Domain\Models\ActivityLog;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Support\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes the human-readable activity feed.
 *
 * Unlike the audit trail this is a product feature: short retention, mutable, and read on
 * dashboards. It is therefore allowed to fail quietly and is never in the critical path of a
 * business operation.
 */
final class ActivityLogger
{
    private ?string $batchId = null;

    public function __construct(private readonly RequestContext $context) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $description,
        ?Model $subject = null,
        ?string $channel = 'default',
        ?string $event = null,
        array $properties = [],
    ): ActivityLog {
        $causer = auth()->user();

        return ActivityLog::query()->create([
            'company_id' => $this->context->companyId(),
            'log_name' => $channel ?? 'default',
            'event' => $event,
            'description' => Str::limit($description, 1000, ''),

            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            // Denormalised so the feed still reads correctly after the subject is renamed or
            // archived — "approved invoice INV-0042" must not become "approved [deleted]".
            'subject_label' => $subject === null ? null : $this->describe($subject),

            'causer_type' => $causer instanceof User ? $causer->getMorphClass() : null,
            'causer_id' => $causer instanceof User ? (string) $causer->getKey() : null,
            'causer_label' => $causer instanceof User ? $causer->fullName() : 'System',

            'properties' => $properties === [] ? null : $properties,
            'batch_id' => $this->batchId,
            'request_id' => $this->context->requestId(),
        ]);
    }

    /**
     * Group everything logged inside the callback into one feed entry.
     *
     * A bulk approval of forty invoices is one thing the user did, and forty lines in the feed
     * makes the feed useless for the next hour.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function batch(callable $callback): mixed
    {
        $previous = $this->batchId;
        $this->batchId = (string) Str::uuid7();

        try {
            return $callback();
        } finally {
            $this->batchId = $previous;
        }
    }

    /**
     * Best-effort human label for a model, using whichever conventional attribute it has.
     */
    private function describe(Model $subject): string
    {
        foreach (['name', 'label', 'title', 'reference', 'number', 'code', 'email'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return Str::limit($value, 200, '');
            }
        }

        if (method_exists($subject, 'fullName')) {
            return Str::limit((string) $subject->fullName(), 200, '');
        }

        return Str::headline(class_basename($subject)).' '.Str::limit((string) $subject->getKey(), 8, '');
    }
}
