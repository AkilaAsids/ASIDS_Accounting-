<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Domain\Enums;

/**
 * The declared type of a setting value.
 *
 * Values are stored as JSONB, so a boolean stays a boolean without a stringly-typed round trip.
 * The type is still recorded because it drives the form control the UI renders and the coercion
 * applied to submitted input — a checkbox posts `"0"`, and without a declared type that becomes
 * a truthy string.
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Array = 'array';
    case Json = 'json';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';

    /**
     * Coerce submitted input to the declared type.
     *
     * HTML forms and JSON clients disagree about how to send a boolean and a number, so coercion
     * happens once, here, rather than in every controller.
     */
    public function coerce(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Text => is_scalar($value) ? (string) $value : null,
            self::Integer => is_numeric($value) ? (int) $value : null,
            self::Float => is_numeric($value) ? (float) $value : null,
            // `filter_var` rather than a cast: "0", "false" and "off" are all falsy to a human
            // and truthy to PHP.
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            self::Array => is_array($value) ? array_values($value) : null,
            self::Json => is_array($value) ? $value : null,
            self::Date, self::DateTime, self::Time => is_string($value) ? $value : null,
        };
    }

    /**
     * The Laravel validation rule for this type, merged with the definition's own rules.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::String => ['string', 'max:1000'],
            self::Text => ['string', 'max:65535'],
            self::Integer => ['integer'],
            self::Float => ['numeric'],
            self::Boolean => ['boolean'],
            self::Array, self::Json => ['array'],
            self::Date => ['date_format:Y-m-d'],
            self::DateTime => ['date'],
            self::Time => ['date_format:H:i'],
        };
    }
}
