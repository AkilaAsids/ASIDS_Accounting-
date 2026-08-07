<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Speculative attribute reads.
 *
 * `Model::getAttribute()` cannot be used to ask "does this model happen to have a `name`?". Under
 * `Model::preventAccessingMissingAttributes()` — which `PlatformServiceProvider` enables everywhere
 * except production — it throws `MissingAttributeException` for a key the model does not carry,
 * rather than returning null.
 *
 * That asymmetry is worse than a plain error would be. Code that probes conventional attribute names
 * works in production and throws in development, CI and staging, so the failure appears only in the
 * environments where it will be blamed on the environment. Both audit writers did exactly this, and
 * it made role assignment and ownership transfer return 500 everywhere but production.
 *
 * `peek()` answers the question honestly: a stored attribute's value, or null.
 */
final class ModelAttributes
{
    /**
     * The value of a stored attribute, or null when the model does not carry it.
     *
     * Deliberately consults the loaded attribute array first and only then reads through
     * `getAttribute()`, so casts and enums still apply to attributes that *are* present. An
     * accessor-only attribute reports null, which is the right answer for a probe: the caller is
     * asking what this row stores, not what the class can compute.
     */
    public static function peek(Model $model, string $key): mixed
    {
        return array_key_exists($key, $model->getAttributes())
            ? $model->getAttribute($key)
            : null;
    }
}
