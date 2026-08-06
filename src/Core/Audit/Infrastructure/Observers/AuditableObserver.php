<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Infrastructure\Observers;

use Asids\Core\Audit\Application\Services\AuditRecorder;
use Asids\Core\Audit\Domain\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Translates Eloquent lifecycle events into audit entries.
 *
 * Hooks the *past-tense* events (`created`, `updated`, `deleted`) rather than the `-ing` ones,
 * so an entry is only written for a change that actually reached the database. Writing on
 * `updating` would record changes that a subsequent validation failure or constraint violation
 * discarded.
 */
final readonly class AuditableObserver
{
    public function __construct(private AuditRecorder $recorder) {}

    public function created(Model $model): void
    {
        $this->recorder->record(
            subject: $model,
            event: AuditEvent::Created,
            oldValues: null,
            newValues: $this->payload($model, $model->getAttributes()),
            tags: $this->tags($model),
        );
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        $filtered = $this->payload($model, $changes);

        // Only excluded columns moved — an `updated_at` touch. AuditRecorder would drop it
        // anyway; returning here saves the round trip.
        if ($filtered === []) {
            return;
        }

        $this->recorder->record(
            subject: $model,
            event: AuditEvent::Updated,
            // `getOriginal` limited to the changed keys: storing every original attribute would
            // double the row size to record what did not change.
            oldValues: $this->payload($model, array_intersect_key($model->getOriginal(), $filtered)),
            newValues: $filtered,
            tags: $this->tags($model),
        );
    }

    public function deleted(Model $model): void
    {
        // A soft delete is an update to `deleted_at`, but it reads as a deletion to anyone
        // reviewing the trail, so it is recorded as one.
        $this->recorder->record(
            subject: $model,
            event: AuditEvent::Deleted,
            oldValues: $this->payload($model, $model->getOriginal()),
            newValues: null,
            tags: $this->tags($model),
        );
    }

    public function restored(Model $model): void
    {
        $this->recorder->record(
            subject: $model,
            event: AuditEvent::Restored,
            oldValues: null,
            newValues: $this->payload($model, $model->getAttributes()),
            tags: $this->tags($model),
        );
    }

    public function forceDeleted(Model $model): void
    {
        // The one irreversible operation on business data, so the entry keeps the full row —
        // this entry is all that will remain of it.
        $this->recorder->record(
            subject: $model,
            event: AuditEvent::ForceDeleted,
            oldValues: $this->payload($model, $model->getOriginal()),
            newValues: null,
            tags: $this->tags($model),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function payload(Model $model, array $attributes): array
    {
        /** @var list<string> $only */
        $only = method_exists($model, 'auditOnly') ? $model->auditOnly() : [];
        /** @var list<string> $excluded */
        $excluded = method_exists($model, 'auditExcluded') ? $model->auditExcluded() : [];

        if ($only !== []) {
            return array_intersect_key($attributes, array_flip($only));
        }

        return array_diff_key($attributes, array_flip($excluded));
    }

    /**
     * @return list<string>
     */
    private function tags(Model $model): array
    {
        /** @var list<string> $tags */
        $tags = method_exists($model, 'auditTags') ? $model->auditTags() : [];

        return $tags;
    }
}
