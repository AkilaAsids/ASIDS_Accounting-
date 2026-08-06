<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Domain\Models;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Settings\Domain\Catalogue\SettingDefinition;
use Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Enums\SettingType;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * A stored setting *override*. Absence of a row means "inherit".
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property SettingScope $scope
 * @property string|null $scope_id
 * @property string $key
 * @property SettingType $type
 * @property mixed $value
 * @property bool $is_encrypted
 */
final class Setting extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'settings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'scope',
        'scope_id',
        'key',
        'type',
        'value',
        'is_encrypted',
        'updated_by_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function definition(): ?SettingDefinition
    {
        return SettingsCatalogue::find($this->key);
    }

    /**
     * The usable value, decrypted and coerced to its declared type.
     *
     * Coercion happens on read as well as on write because the definition's type can change with
     * a release — an integer that was once stored as a string must still read as an integer.
     */
    public function resolvedValue(): mixed
    {
        $raw = $this->value;

        if ($this->is_encrypted && is_string($raw)) {
            $raw = Crypt::decryptString($raw);
        }

        return $this->type->coerce($raw);
    }

    public function tenantIsOptional(): bool
    {
        return true;
    }

    /**
     * System-scope rows are platform-owned and carry a NULL tenant; they must remain visible from
     * inside a workspace, since they are the outermost fallback.
     */
    public function tenantScopeIncludesPlatformRows(): bool
    {
        return true;
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeAtScope(Builder $query, SettingScope $scope, ?string $scopeId = null): Builder
    {
        return $query
            ->where('scope', $scope->value)
            ->when(
                $scopeId === null,
                static fn ($q) => $q->whereNull('scope_id'),
                static fn ($q) => $q->where('scope_id', $scopeId),
            );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => SettingScope::class,
            'type' => SettingType::class,
            // JSONB, so a boolean stays a boolean and a list stays a list.
            'value' => 'json',
            'is_encrypted' => 'boolean',
        ];
    }
}
