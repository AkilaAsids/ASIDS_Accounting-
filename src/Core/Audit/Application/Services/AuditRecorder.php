<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Application\Services;

use Asids\Core\Audit\Domain\Enums\ActorType;
use Asids\Core\Audit\Domain\Enums\AuditEvent;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Support\ModelAttributes;
use Asids\Core\Platform\Support\RequestContext;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes audit entries.
 *
 * Three properties, in priority order:
 *
 *   1. **Synchronous and in the caller's transaction.** The entry is inserted alongside the
 *      change it describes, so a rollback takes both and a crash cannot leave a change with no
 *      record. Queueing the write would be faster and would occasionally lose entries — the
 *      wrong trade for a system of financial record.
 *
 *   2. **Lock-free.** No chain is computed here; `previous_hash`, `hash` and `sealed_at` are
 *      left null for `asids:audit-seal` to fill in. Computing the chain inline would require
 *      reading the tenant's latest hash under a lock held for the whole business transaction,
 *      serialising every audited write in the workspace.
 *
 *   3. **Never the cause of a failure the user sees.** A malformed value or an oversized
 *      payload must not fail an otherwise valid business operation, so the insert is guarded
 *      and falls back to the `audit` log channel. That fallback is itself alarmed on: a
 *      persistently failing audit write is a compliance incident, not a warning to ignore.
 */
final readonly class AuditRecorder
{
    /**
     * Values above this size are replaced by a marker. A 40MB attachment payload in an audit
     * row would make the table unqueryable and the JSONB index useless.
     */
    private const int MAX_VALUE_LENGTH = 8192;

    public function __construct(private RequestContext $context) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  list<string>  $tags
     */
    public function record(
        Model $subject,
        AuditEvent $event,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $tags = [],
        ?string $reason = null,
    ): void {
        try {
            $old = $oldValues === null ? null : $this->sanitise($oldValues);
            $new = $newValues === null ? null : $this->sanitise($newValues);

            $changed = ($old !== null && $new !== null)
                ? array_keys(array_diff_key($new, array_intersect_assoc($new, $old)))
                : ($new === null ? [] : array_keys($new));

            // Nothing of substance changed — an `updated_at` touch, or a save that set a column
            // to the value it already had. Recording it would bury real changes in noise.
            if ($event === AuditEvent::Updated && $changed === []) {
                return;
            }

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->resolveTenantId($subject),
                'company_id' => $this->resolveCompanyId($subject),

                // The morph alias, not the class name: a namespace refactor must not orphan the
                // history. `enforceMorphMap` guarantees an alias exists.
                'auditable_type' => $subject->getMorphClass(),
                'auditable_id' => (string) $subject->getKey(),
                'event' => $event->value,

                'old_values' => $old === null ? null : $this->encode($old),
                'new_values' => $new === null ? null : $this->encode($new),
                'changed_attributes' => $this->encode($changed),

                ...$this->actorColumns(),

                'ip_address' => request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 1000, ''),
                'request_method' => request()->method(),
                'request_url' => Str::limit(request()->fullUrl(), 2000, ''),
                'request_id' => $this->context->requestId(),
                'channel' => $this->context->channel(),
                'tags' => $tags === [] ? null : $this->encode($tags),
                'reason' => $reason,

                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The business operation proceeds. The entry lands on a durable channel with enough
            // detail to be replayed, and the failure is loud.
            Log::channel('audit')->critical('Audit entry could not be written to the database.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'auditable_type' => $subject->getMorphClass(),
                'auditable_id' => $subject->getKey(),
                'event' => $event->value,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                ...$this->context->toArray(),
            ]);
        }
    }

    /**
     * Record something that is not a model change — a sign-in, an export, a permission grant.
     *
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $tags
     */
    public function recordAction(
        Model $subject,
        AuditEvent $event,
        array $properties = [],
        array $tags = [],
        ?string $reason = null,
    ): void {
        $this->record(
            subject: $subject,
            event: $event,
            oldValues: null,
            newValues: $properties === [] ? null : $properties,
            tags: $tags,
            reason: $reason,
        );
    }

    /**
     * Redacts credentials and truncates oversized values.
     *
     * The redaction list is shared with the log scrubber, so a value that never reaches a log
     * line never reaches the audit trail either. Without this, "audit everything" means storing
     * password hashes and TOTP secrets in a table a wider group of people can read than can read
     * the users table.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitise(array $values): array
    {
        /** @var list<string> $redacted */
        $redacted = config('asids.audit.redacted_attributes', []);
        $marker = (string) config('asids.audit.redaction_marker', '[redacted]');

        $result = [];

        foreach ($values as $key => $value) {
            $normalised = strtolower((string) $key);

            foreach ($redacted as $needle) {
                if (str_contains($normalised, strtolower($needle))) {
                    // The *fact* of the change is retained even though the value is not: "the
                    // password changed" is the auditable event, not what it changed to.
                    $result[$key] = $marker;

                    continue 2;
                }
            }

            if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
                $result[$key] = sprintf('[truncated: %d bytes]', strlen($value));

                continue;
            }

            // Enums and dates are flattened to scalars so the JSONB stays queryable rather than
            // holding an object shape that depends on a PHP class.
            $result[$key] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Who is acting. Resolved from the request context rather than from `auth()` so that a
     * queued job, a console command and an impersonated request each attribute correctly.
     *
     * @return array<string, mixed>
     */
    private function actorColumns(): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            return [
                'actor_type' => ActorType::User->value,
                'actor_id' => (string) $user->getKey(),
                // Denormalised on purpose: the trail must still read correctly years after the
                // account is deactivated and renamed.
                'actor_label' => $user->fullName().' <'.$user->email.'>',
                'impersonator_id' => $this->context->impersonatorId(),
                'access_token_id' => $this->context->accessTokenId(),
            ];
        }

        $type = match (true) {
            app()->runningInConsole() => ActorType::Console,
            $this->context->channel() === 'queue' => ActorType::Job,
            default => ActorType::System,
        };

        return [
            'actor_type' => $type->value,
            'actor_id' => null,
            'actor_label' => $type->label(),
            'impersonator_id' => null,
            'access_token_id' => null,
        ];
    }

    /**
     * The tenant the entry belongs to.
     *
     * Read from the subject when it carries one, because a queued job's ambient context may
     * legitimately differ from the row it is modifying — and the entry must follow the data, not
     * the worker.
     */
    private function resolveTenantId(Model $subject): ?string
    {
        $fromSubject = ModelAttributes::peek($subject, 'tenant_id');

        if (is_string($fromSubject)) {
            return $fromSubject;
        }

        return $this->context->tenantId();
    }

    private function resolveCompanyId(Model $subject): ?string
    {
        $fromSubject = ModelAttributes::peek($subject, 'company_id');

        return is_string($fromSubject) ? $fromSubject : $this->context->companyId();
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
