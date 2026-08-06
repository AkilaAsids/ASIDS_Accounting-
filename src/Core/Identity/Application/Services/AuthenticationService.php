<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\Services;

use Asids\Core\Identity\Domain\Enums\LoginOutcome;
use Asids\Core\Identity\Domain\Exceptions\AccountInactive;
use Asids\Core\Identity\Domain\Exceptions\AccountLocked;
use Asids\Core\Identity\Domain\Exceptions\AuthenticationFailed;
use Asids\Core\Identity\Domain\Exceptions\TwoFactorChallengeExpired;
use Asids\Core\Identity\Domain\Models\LoginHistory;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Support\RequestContext;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The sign-in state machine.
 *
 * Password verification and second-factor verification are separate steps joined by
 * a short-lived, single-use challenge held in the cache. That shape is what allows
 * one implementation to serve both the cookie-based SPA and token-based mobile
 * clients: neither needs a session to hold the intermediate state.
 *
 * Two behaviours are worth calling out because they are easy to get wrong:
 *
 *   * The user lookup is tenant scoped by the global scope, so credentials valid in
 *     one workspace do not authenticate in another even when the same person holds
 *     accounts in both.
 *
 *   * A dummy hash comparison runs when the address is unknown, so the response
 *     time does not reveal whether an account exists.
 */
final readonly class AuthenticationService
{
    /**
     * A bcrypt hash of a random value, compared against when no user is found so
     * that the failure path costs the same as the success path.
     */
    private const string TIMING_EQUALISER = '$2y$12$8Kx.Zt1TzZmVYqk1nFqLbeCJ8sQfKk6oQmZ4pKNQ9CJHl0aFhKZAu';

    public function __construct(
        private StatefulGuard $guard,
        private Hasher $hasher,
        private CacheRepository $cache,
        private TwoFactorService $twoFactor,
        private DeviceService $devices,
        private RequestContext $context,
    ) {}

    /**
     * Step one: verify the password.
     *
     * @return array{status: 'authenticated', user: User}|array{status: 'two_factor_required', challenge: string, expires_in: int}
     */
    public function attempt(Request $request, string $email, string $password, bool $remember = false): array
    {
        $email = strtolower(trim($email));

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            // Equalise timing, then fail identically to a wrong password.
            $this->hasher->check($password, self::TIMING_EQUALISER);
            $this->record($request, null, $email, LoginOutcome::Failed, 'unknown_account');

            throw new AuthenticationFailed();
        }

        if ($user->isLocked()) {
            $this->record($request, $user, $email, LoginOutcome::LockedOut);

            throw AccountLocked::until($user->locked_until);
        }

        if ($user->password === null || ! $this->hasher->check($password, $user->password)) {
            $this->registerFailure($user);
            $this->record($request, $user, $email, LoginOutcome::Failed, 'invalid_password');

            throw new AuthenticationFailed();
        }

        // Status is checked only after the password is verified: doing it first
        // would let an attacker distinguish a suspended account from a
        // non-existent one without knowing any credential.
        if (! $user->status->canAuthenticate()) {
            $this->record($request, $user, $email, LoginOutcome::AccountInactive, $user->status->value);

            throw AccountInactive::because($user->status);
        }

        $needsSecondFactor = $user->hasTwoFactorEnabled()
            && ! $this->devices->isTrusted($request, $user);

        if ($needsSecondFactor) {
            $this->record($request, $user, $email, LoginOutcome::TwoFactorRequired);

            return $this->issueChallenge($user, $remember);
        }

        $this->completeSignIn($request, $user, $remember, twoFactorMethod: null);

        return ['status' => 'authenticated', 'user' => $user];
    }

    /**
     * Step two: verify the second factor and finish signing in.
     */
    public function completeTwoFactorChallenge(
        Request $request,
        string $challenge,
        string $code,
        bool $trustDevice = false,
    ): User {
        $key = $this->challengeKey($challenge);

        /** @var array{user_id: string, remember: bool}|null $payload */
        $payload = $this->cache->get($key);

        if ($payload === null) {
            throw new TwoFactorChallengeExpired();
        }

        $user = User::query()->find($payload['user_id']);

        if ($user === null || ! $user->status->canAuthenticate()) {
            $this->cache->forget($key);

            throw new TwoFactorChallengeExpired();
        }

        if ($user->isLocked()) {
            throw AccountLocked::until($user->locked_until);
        }

        try {
            $method = $this->twoFactor->verify($user, $code);
        } catch (\Throwable $e) {
            // A wrong second factor counts towards lockout: at this point the
            // attacker already holds the password, so the second factor is the only
            // remaining control and must not be brute forceable.
            $this->registerFailure($user);
            $this->record($request, $user, $user->email, LoginOutcome::TwoFactorFailed);

            throw $e;
        }

        // Single use: the challenge is consumed whether or not the caller retries.
        $this->cache->forget($key);

        $device = $this->completeSignIn($request, $user, $payload['remember'], $method);

        if ($trustDevice && $method === 'totp') {
            // Never extend trust off the back of a recovery code — that is the
            // credential someone uses when they have lost the device.
            $this->devices->trust($device);
        }

        return $user;
    }

    public function signOut(Request $request): void
    {
        $user = $request->user();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        if ($user instanceof User && $sessionId !== null) {
            LoginHistory::query()
                ->where('user_id', $user->getKey())
                ->where('session_id', $sessionId)
                ->whereNull('logged_out_at')
                ->update(['logged_out_at' => now()]);
        }

        $this->guard->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * @return array{status: 'two_factor_required', challenge: string, expires_in: int}
     */
    private function issueChallenge(User $user, bool $remember): array
    {
        $challenge = Str::random(64);
        $ttl = 300;

        $this->cache->put(
            key: $this->challengeKey($challenge),
            value: ['user_id' => (string) $user->getKey(), 'remember' => $remember],
            ttl: $ttl,
        );

        return [
            'status' => 'two_factor_required',
            'challenge' => $challenge,
            'expires_in' => $ttl,
        ];
    }

    private function completeSignIn(
        Request $request,
        User $user,
        bool $remember,
        ?string $twoFactorMethod,
    ): \Asids\Core\Identity\Domain\Models\UserDevice {
        // `login()` already migrates the session id (SessionGuard::updateSession calls
        // `migrate(true)`), so session fixation is handled by the framework. Calling
        // `regenerate()` again here migrated a second time and left the authenticated
        // state on the discarded row: `sessions.user_id` was populated but the payload
        // had no `login_web` key, so every subsequent request presented a valid cookie
        // for a session that contained no user and was rejected as unauthenticated.
        $this->guard->login($user, $remember);

        $device = $this->devices->recognise($request, $user);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_activity_at' => now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->context->setActorId((string) $user->getKey());

        $this->record(
            request: $request,
            user: $user,
            email: $user->email,
            outcome: LoginOutcome::Succeeded,
            reason: null,
            device: $device,
            twoFactorMethod: $twoFactorMethod,
        );

        return $device;
    }

    /**
     * Increments the failure counter and locks the account when the threshold is
     * crossed. This is per-account state, complementary to the per-IP rate limiter
     * on the route: the limiter stops a fast attack, this stops a slow one.
     */
    private function registerFailure(User $user): void
    {
        /** @var array{max_attempts:int, decay_minutes:int} $policy */
        $policy = config('asids.auth.lockout');

        $attempts = $user->failed_login_attempts + 1;

        $attributes = ['failed_login_attempts' => $attempts];

        if ($attempts >= $policy['max_attempts']) {
            $attributes['locked_until'] = now()->addMinutes($policy['decay_minutes']);
            $attributes['failed_login_attempts'] = 0;

            Log::channel('security')->warning('Account locked after repeated failures.', [
                'user_id' => $user->getKey(),
                'attempts' => $attempts,
            ]);
        }

        $user->forceFill($attributes)->save();
    }

    private function record(
        Request $request,
        ?User $user,
        string $email,
        LoginOutcome $outcome,
        ?string $reason = null,
        ?\Asids\Core\Identity\Domain\Models\UserDevice $device = null,
        ?string $twoFactorMethod = null,
    ): void {
        LoginHistory::query()->create([
            'user_id' => $user?->getKey(),
            'device_id' => $device?->getKey(),
            'email_attempted' => $email,
            'outcome' => $outcome,
            'failure_reason' => $reason,
            'channel' => $this->context->channel(),
            'ip_address' => (string) $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'two_factor_used' => $twoFactorMethod !== null,
            'two_factor_method' => $twoFactorMethod,
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
        ]);

        if (! $outcome->isSuccessful()) {
            Log::channel('security')->info('Authentication attempt failed.', [
                'outcome' => $outcome->value,
                'reason' => $reason,
                'email' => $email,
                'ip' => $request->ip(),
            ]);
        }
    }

    private function challengeKey(string $challenge): string
    {
        return 'two-factor:challenge:'.hash('sha256', $challenge);
    }
}
