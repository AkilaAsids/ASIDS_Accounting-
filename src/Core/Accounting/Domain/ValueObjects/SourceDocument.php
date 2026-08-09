<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\ValueObjects;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The document that caused a ledger entry.
 *
 * A pair rather than two loose strings, for the same reason the database has a CHECK asserting both
 * or neither: a half-set reference is a row nobody can resolve, and it is exactly what a partially
 * applied bug produces. Constructing this object is the only way to express a source in the
 * application, and it cannot be constructed half-made.
 *
 * `type` is a morph *alias*, never a class name — because an alias survives the namespace change
 * that would orphan every row holding a fully-qualified name.
 *
 * Enforcing that takes an explicit round-trip, and it is worth saying why. `Relation::requiresMorphMap()`
 * is true platform-wide, but that enforcement governs Eloquent's morph *relations*; it does not reach
 * `getMorphAlias()`, which answers with the class name for a model nobody mapped. So the alias is fed
 * back through `getMorphedModel()`: a mapped alias resolves to its class, an unmapped one comes back
 * null, and only the second case can be distinguished from a legitimate alias by inspection.
 *
 * The failure this prevents is quiet and late. Without the check, forgetting to register a document
 * in its module's morph map writes a class name into `source_type`, and nothing complains until
 * someone opens a ledger line months later and finds a source that resolves to nothing. Checking at
 * construction moves that to the moment the developer is looking at the code.
 */
final readonly class SourceDocument
{
    private function __construct(
        public string $type,
        public string $id,
    ) {}

    public function __toString(): string
    {
        return $this->type.':'.$this->id;
    }

    /**
     * The source of an entry, taken from the model that caused it.
     */
    public static function for(Model $model): self
    {
        $key = $model->getKey();

        if (! is_string($key) || $key === '') {
            throw BusinessRuleViolation::make(
                'unsaved-source-document',
                'A journal entry cannot cite an unsaved document as its source. Save the document first, '
                .'inside the same transaction, so the entry and the thing that caused it commit together.',
            );
        }

        // Returns the class name unchanged when the model is not in the map — it does not throw, even
        // with the map enforced. The round-trip below is what turns that into a failure.
        $alias = Relation::getMorphAlias($model::class);

        // The morph map is keyed `array<int|string, class-string>`, so an alias registered as a bare
        // integer is possible. It would be a misconfiguration — the column stores a name a reader is
        // meant to recognise — and it is caught here rather than becoming a numeric string in the
        // database that resolves to nothing.
        if (! is_string($alias)) {
            throw BusinessRuleViolation::make(
                'non-string-morph-alias',
                sprintf(
                    'The morph alias registered for %s is not a string. A source document type must be a '
                    .'readable name, not an ordinal.',
                    $model::class,
                ),
            );
        }

        // The round trip. A mapped alias resolves back to a class; an unmapped one resolves to null,
        // which is the only way to tell "alias" from "class name we were handed instead".
        if (Relation::getMorphedModel($alias) === null) {
            throw BusinessRuleViolation::make(
                'unmapped-source-document',
                sprintf(
                    '%s has no morph alias, so a ledger entry citing it would store a class name that a '
                    .'later rename would orphan. Register it in its module service provider\'s morph map.',
                    $model::class,
                ),
            );
        }

        return new self($alias, $key);
    }

    /**
     * Rebuild from what the database holds.
     *
     * Returns null when there is no source, so a caller can pass the two columns straight through
     * without first deciding whether they are set.
     */
    public static function fromColumns(?string $type, ?string $id): ?self
    {
        if ($type === null && $id === null) {
            return null;
        }

        if ($type === null || $id === null) {
            throw BusinessRuleViolation::make(
                'incomplete-source-document',
                'A journal entry has half a source document reference. Both the type and the id must be '
                .'present, or neither.',
            );
        }

        return new self($type, $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
