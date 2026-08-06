<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Domain\Catalogue;

use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Enums\SettingType;

/**
 * Every setting the platform offers.
 *
 * Phase 1 defines the settings the modules built so far actually consume. Nothing is listed
 * speculatively: a key with no reader is a control that appears to do something and does not,
 * which is worse than an absent one.
 *
 * Later phases add their own groups — invoice numbering, tax defaults, payroll cycles — by
 * extending the arrays below.
 */
final class SettingsCatalogue
{
    /**
     * @return list<SettingDefinition>
     */
    public static function all(): array
    {
        return [
            ...self::localisation(),
            ...self::security(),
            ...self::branding(),
            ...self::notifications(),
        ];
    }

    public static function find(string $key): ?SettingDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array<string, SettingDefinition> keyed by setting key
     */
    public static function keyed(): array
    {
        $keyed = [];

        foreach (self::all() as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }

    /**
     * Settings readable without the settings-view permission — the ones the interface needs in
     * order to render at all.
     *
     * @return list<string>
     */
    public static function publicKeys(): array
    {
        return array_values(array_map(
            static fn (SettingDefinition $d): string => $d->key,
            array_filter(self::all(), static fn (SettingDefinition $d): bool => $d->public),
        ));
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function localisation(): array
    {
        return [
            new SettingDefinition(
                key: 'localisation.date_format',
                type: SettingType::String,
                default: 'd/m/Y',
                label: 'Date format',
                description: 'How dates are displayed throughout the interface and on documents.',
                group: 'localisation',
                // Personal, because a Sri Lankan office may include an expatriate accountant who
                // reads d/m/Y as m/d/Y and will misread every date until they can change it.
                overridableAt: [SettingScope::User, SettingScope::Company, SettingScope::Tenant],
                options: [
                    'd/m/Y' => '31/03/2026',
                    'Y-m-d' => '2026-03-31',
                    'd M Y' => '31 Mar 2026',
                    'm/d/Y' => '03/31/2026',
                ],
                public: true,
                sortOrder: 10,
            ),
            new SettingDefinition(
                key: 'localisation.time_format',
                type: SettingType::String,
                default: 'H:i',
                label: 'Time format',
                description: 'Whether times are shown on a 24-hour or 12-hour clock.',
                group: 'localisation',
                overridableAt: [SettingScope::User, SettingScope::Tenant],
                options: ['H:i' => '14:30', 'h:i A' => '02:30 PM'],
                public: true,
                sortOrder: 20,
            ),
            new SettingDefinition(
                key: 'localisation.number_format',
                type: SettingType::String,
                default: 'lakh',
                label: 'Number grouping',
                description: 'Thousands grouping. The lakh/crore system groups as 12,34,567 and is standard in South Asia.',
                group: 'localisation',
                overridableAt: [SettingScope::Company, SettingScope::Tenant],
                options: ['western' => '1,234,567', 'lakh' => '12,34,567'],
                public: true,
                sortOrder: 30,
            ),
            new SettingDefinition(
                key: 'localisation.week_starts_on',
                type: SettingType::Integer,
                default: 1,
                label: 'Week starts on',
                description: 'First day of the week in calendars and weekly reports.',
                group: 'localisation',
                overridableAt: [SettingScope::User, SettingScope::Tenant],
                rules: ['min:0', 'max:6'],
                options: [0 => 'Sunday', 1 => 'Monday'],
                public: true,
                sortOrder: 40,
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function security(): array
    {
        return [
            new SettingDefinition(
                key: 'security.require_two_factor',
                type: SettingType::Boolean,
                default: false,
                label: 'Require two factor authentication',
                description: 'Every user must enrol before they can use the workspace. Strongly recommended for workspaces handling payroll or banking.',
                group: 'security',
                // Workspace-only: a per-user override would let anyone opt out of the control.
                overridableAt: [SettingScope::Tenant],
                sortOrder: 10,
            ),
            new SettingDefinition(
                key: 'security.session_idle_timeout',
                type: SettingType::Integer,
                default: 30,
                label: 'Sign out after inactivity (minutes)',
                description: 'Zero disables the idle timeout. A shared office terminal warrants a short value.',
                group: 'security',
                overridableAt: [SettingScope::Tenant],
                rules: ['min:0', 'max:1440'],
                sortOrder: 20,
            ),
            new SettingDefinition(
                key: 'security.password_expiry_days',
                type: SettingType::Integer,
                default: 180,
                label: 'Require a new password every (days)',
                description: 'Zero disables expiry. Modern guidance favours length and breach checking over forced rotation, so a long interval or zero is a defensible choice.',
                group: 'security',
                overridableAt: [SettingScope::Tenant],
                rules: ['min:0', 'max:1095'],
                sortOrder: 30,
            ),
            new SettingDefinition(
                key: 'security.trusted_device_days',
                type: SettingType::Integer,
                default: 30,
                label: 'Remember trusted devices for (days)',
                description: 'How long a device may skip the two factor challenge after being trusted. Zero disables device trust entirely.',
                group: 'security',
                overridableAt: [SettingScope::Tenant],
                rules: ['min:0', 'max:400'],
                sortOrder: 40,
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function branding(): array
    {
        return [
            new SettingDefinition(
                key: 'branding.primary_colour',
                type: SettingType::String,
                default: '#0f766e',
                label: 'Primary colour',
                description: 'Used for buttons, links and highlights. The interface reads this at runtime, so no rebuild is needed.',
                group: 'branding',
                overridableAt: [SettingScope::Tenant],
                rules: ['regex:/^#[0-9a-fA-F]{6}$/'],
                public: true,
                sortOrder: 10,
            ),
            new SettingDefinition(
                key: 'branding.default_theme',
                type: SettingType::String,
                default: 'system',
                label: 'Default appearance',
                description: 'The theme new users start with. Each user can still choose their own.',
                group: 'branding',
                overridableAt: [SettingScope::Tenant],
                rules: ['in:system,light,dark'],
                options: ['system' => 'Match the device', 'light' => 'Light', 'dark' => 'Dark'],
                public: true,
                sortOrder: 20,
            ),
            new SettingDefinition(
                key: 'branding.document_footer',
                type: SettingType::Text,
                default: '',
                label: 'Document footer',
                description: 'Printed at the foot of every invoice, quotation and statement. Bank details and payment terms belong here.',
                group: 'branding',
                overridableAt: [SettingScope::Company, SettingScope::Tenant],
                sortOrder: 30,
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function notifications(): array
    {
        return [
            new SettingDefinition(
                key: 'notifications.security_alerts',
                type: SettingType::Boolean,
                default: true,
                label: 'Send security alerts by e-mail',
                description: 'Sign-ins from a new device, and changes to your own security settings. Password change confirmations are always sent and are not covered by this.',
                group: 'notifications',
                overridableAt: [SettingScope::User, SettingScope::Tenant],
                sortOrder: 10,
            ),
            new SettingDefinition(
                key: 'notifications.digest_frequency',
                type: SettingType::String,
                default: 'daily',
                label: 'Activity digest',
                description: 'How often a summary of workspace activity is e-mailed to you.',
                group: 'notifications',
                overridableAt: [SettingScope::User, SettingScope::Tenant],
                rules: ['in:never,daily,weekly'],
                options: ['never' => 'Never', 'daily' => 'Daily', 'weekly' => 'Weekly'],
                sortOrder: 20,
            ),
        ];
    }
}
