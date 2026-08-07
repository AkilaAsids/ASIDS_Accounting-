<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    public const string PASSWORD = 'password-for-testing-only';

    protected $model = User::class;

    /**
     * The password every factory-made user gets. Hashed once as a static so a test creating
     * fifty users pays for one bcrypt round rather than fifty — with BCRYPT_ROUNDS=4 in
     * phpunit.xml this is the difference between a fast suite and a slow one.
     */
    private static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        self::$passwordHash ??= Hash::make(self::PASSWORD);

        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$passwordHash,
            'password_changed_at' => now(),
            'status' => UserStatus::Active,
            'is_platform_admin' => false,
            'timezone' => 'Asia/Colombo',
            'locale' => 'en',
            'theme' => 'system',
            'invitation_accepted_at' => now(),
        ];
    }

    public function invited(): self
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::PendingInvitation,
            // No password at all: that null credential is what makes the invitation link
            // single-use, so a factory that set one would not exercise the real flow.
            'password' => null,
            'password_changed_at' => null,
            'email_verified_at' => null,
            'invited_at' => now(),
            'invitation_accepted_at' => null,
        ]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => UserStatus::Suspended]);
    }

    public function deactivated(): self
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::Deactivated,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Left the organisation',
        ]);
    }

    /**
     * ASIDS staff: no tenant, `is_platform_admin` true. The database enforces that those two
     * always agree, so this state must set both.
     */
    public function platformAdmin(): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);
    }

    public function withTwoFactor(): self
    {
        return $this->state(fn (): array => [
            // A valid base32 secret so TwoFactorService can actually verify a generated code
            // against it, rather than a random string that would fail decoding.
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function lockedOut(): self
    {
        return $this->state(fn (): array => [
            'failed_login_attempts' => 0,
            'locked_until' => now()->addMinutes(15),
        ]);
    }

    public function withExpiredPassword(): self
    {
        return $this->state(fn (): array => [
            'password_changed_at' => now()->subDays(400),
        ]);
    }
}
