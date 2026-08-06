<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Two factor authentication was confirmed and is now in force.
 *
 * Fired on confirmation, not on enrolment: an enrolment that was never confirmed protects
 * nothing, and treating it as enabled would misreport the workspace's security posture.
 */
final class TwoFactorEnabled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly User $user) {}
}
