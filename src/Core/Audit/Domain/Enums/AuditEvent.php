<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Domain\Enums;

/**
 * What happened. Mirrors the `audit_logs_event_check` constraint exactly — the database is
 * the backstop, this enum is the vocabulary code uses.
 */
enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case ForceDeleted = 'force_deleted';

    /** Reads of sensitive data, recorded because access itself is auditable. */
    case Viewed = 'viewed';
    case Exported = 'exported';

    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case Voided = 'voided';

    case Login = 'login';
    case Logout = 'logout';
    case PermissionChanged = 'permission_changed';
    case SettingChanged = 'setting_changed';
    case ImpersonationStarted = 'impersonation_started';
    case ImpersonationEnded = 'impersonation_ended';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Restored => 'Restored',
            self::ForceDeleted => 'Permanently deleted',
            self::Viewed => 'Viewed',
            self::Exported => 'Exported',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Posted => 'Posted',
            self::Voided => 'Voided',
            self::Login => 'Signed in',
            self::Logout => 'Signed out',
            self::PermissionChanged => 'Permissions changed',
            self::SettingChanged => 'Setting changed',
            self::ImpersonationStarted => 'Impersonation started',
            self::ImpersonationEnded => 'Impersonation ended',
        };
    }

    /**
     * Events that an auditor or incident responder will always want surfaced, regardless of
     * which filters the UI defaults to. Privilege and credential changes are the first thing
     * anyone reconstructs after a security incident.
     */
    public function isHighSignal(): bool
    {
        return in_array($this, [
            self::PermissionChanged,
            self::ImpersonationStarted,
            self::ImpersonationEnded,
            self::ForceDeleted,
            self::Voided,
            self::Exported,
        ], true);
    }
}
