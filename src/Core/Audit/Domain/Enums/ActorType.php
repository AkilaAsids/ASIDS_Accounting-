<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Domain\Enums;

/**
 * Who or what caused a change.
 *
 * The distinction matters when reading a trail: "the system did it" and "someone did it" call
 * for entirely different follow-up, and collapsing them into a nullable user id makes an
 * unattributed change indistinguishable from a scheduled job.
 */
enum ActorType: string
{
    case User = 'user';

    /** A framework or platform action with no human behind it. */
    case System = 'system';

    /** An integration authenticating with a personal access token. */
    case ApiToken = 'api_token';

    case Console = 'console';
    case Job = 'job';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::System => 'System',
            self::ApiToken => 'API integration',
            self::Console => 'Console command',
            self::Job => 'Background job',
        };
    }

    public function requiresIdentity(): bool
    {
        return $this === self::User;
    }
}
