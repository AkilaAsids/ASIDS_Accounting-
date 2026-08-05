<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Support\Logging;

use Asids\Core\Platform\Support\RequestContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps tenant, actor and request identifiers onto every log record, and scrubs
 * anything that looks like a credential out of the context array.
 *
 * The scrubbing matters more than the stamping. Developers log `$request->all()`
 * while debugging and forget to remove it; without a scrubber that habit puts
 * plaintext passwords and TOTP secrets into a log aggregator readable by a wider
 * group of people than can read the database.
 */
final class TenantContextProcessor implements ProcessorInterface
{
    /** @var list<string> */
    private readonly array $redactedKeys;

    private readonly string $marker;

    public function __construct()
    {
        /** @var list<string> $keys */
        $keys = config('asids.audit.redacted_attributes', []);

        $this->redactedKeys = array_map('strtolower', $keys);
        $this->marker = (string) config('asids.audit.redaction_marker', '[redacted]');
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        /** @var RequestContext $context */
        $context = app(RequestContext::class);

        return $record->with(
            context: $this->redact([...$record->context, ...$context->toArray()]),
            extra: [
                ...$record->extra,
                'app_env' => (string) config('app.env'),
                'app_version' => (string) config('app.version', 'dev'),
            ],
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data, int $depth = 0): array
    {
        // A hard depth limit prevents a deeply nested — or self-referential —
        // context array from turning a log write into a stack overflow.
        if ($depth > 8) {
            return ['truncated' => true];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isRedacted($key)) {
                $result[$key] = $this->marker;

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value, $depth + 1) : $value;
        }

        return $result;
    }

    private function isRedacted(string $key): bool
    {
        $normalised = strtolower($key);

        foreach ($this->redactedKeys as $redacted) {
            if (str_contains($normalised, $redacted)) {
                return true;
            }
        }

        return false;
    }
}
