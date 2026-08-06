<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Application\Services\AccountLinkService;
use Asids\Core\Identity\Application\Services\PasswordPolicyService;
use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Events\PasswordChanged;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Presentation\Http\Requests\ChangePasswordRequest;
use Asids\Core\Identity\Presentation\Http\Requests\ConsumeAccountLinkRequest;
use Asids\Core\Identity\Presentation\Http\Requests\ForgotPasswordRequest;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Password changes, forgotten-password requests, and consumption of signed
 * invitation / reset links.
 */
final class PasswordController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly PasswordPolicyService $passwords,
        private readonly AccountLinkService $links,
    ) {}

    /**
     * Request a reset link.
     *
     * Always answers 202 with the same message, whether or not the address exists. Anything
     * else — a different message, a different status, even a measurably different response
     * time — turns this endpoint into an account enumeration oracle, and a customer list is
     * exactly what a competitor or an attacker wants from an SME accounting platform.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower((string) $request->validated('email'));

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null && $user->status->canAuthenticate()) {
            $url = $this->users->sendPasswordResetLink($user);

            // Dispatched through the event so the notification channel, throttling and audit
            // entry are handled in one place rather than inline here.
            PasswordChanged::dispatch($user, 'reset-requested');

            Log::channel('security')->info('Password reset link issued.', [
                'user_id' => $user->getKey(),
                'ip' => $request->ip(),
            ]);

            $user->notify(new \Asids\Core\Identity\Presentation\Notifications\PasswordResetLink($url));
        }

        return ApiResponse::accepted(
            'If an account exists for that address, a reset link is on its way. Check your inbox, including the spam folder.'
        );
    }

    /**
     * Consume a signed link — either an invitation or a password reset.
     *
     * One endpoint for both because the mechanics are identical (verify the signature, verify
     * the credential fingerprint, set a password) and the difference is only which state
     * transition follows. Two endpoints would mean two chances to get the verification wrong.
     */
    public function consumeLink(ConsumeAccountLinkRequest $request, User $user): JsonResponse
    {
        $purpose = (string) $request->validated('purpose');

        // The `signed` middleware on the route has already verified the signature and expiry;
        // this checks the part it cannot know — that the credential state the link was bound to
        // has not changed, which is what makes the link single-use.
        $this->links->verify($user, $purpose, (string) $request->validated('fp'));

        $password = (string) $request->validated('password');

        $updated = match ($purpose) {
            AccountLinkService::PURPOSE_INVITATION => $this->users->acceptInvitation($user, $password),
            AccountLinkService::PURPOSE_PASSWORD_RESET => $this->users->resetPassword($user, $password),
            default => throw BusinessRuleViolation::make(
                code: 'unknown-link-purpose',
                message: 'This link is not recognised.',
            ),
        };

        PasswordChanged::dispatch(
            $updated,
            $purpose === AccountLinkService::PURPOSE_INVITATION ? 'invitation' : 'reset-link',
        );

        // The user is deliberately NOT signed in here. Requiring them to sign in with the
        // password they just chose confirms it works and reaches them through the ordinary
        // path — including the two factor challenge, which this flow must not bypass.
        return ApiResponse::item([
            'completed' => true,
            'email' => $updated->email,
            'message' => $purpose === AccountLinkService::PURPOSE_INVITATION
                ? 'Your account is ready. Sign in with your new password.'
                : 'Your password has been changed. Sign in with your new password.',
        ]);
    }

    /**
     * Metadata for the "set your password" screen, so the SPA can show whose account it is and
     * which policy applies before the user types anything.
     */
    public function inspectLink(ConsumeAccountLinkRequest $request, User $user): JsonResponse
    {
        $this->links->verify($user, (string) $request->validated('purpose'), (string) $request->validated('fp'));

        /** @var array{min_length:int, require_mixed_case:bool, require_numbers:bool, require_symbols:bool} $policy */
        $policy = config('asids.auth.password');

        return ApiResponse::item([
            'valid' => true,
            'purpose' => $request->validated('purpose'),
            'email' => $user->email,
            'first_name' => $user->first_name,
            'workspace' => $user->tenant?->name,
            'password_policy' => [
                'min_length' => $policy['min_length'],
                'requires_mixed_case' => $policy['require_mixed_case'],
                'requires_numbers' => $policy['require_numbers'],
                'requires_symbols' => $policy['require_symbols'],
            ],
        ]);
    }

    /**
     * Self-service password change for a signed-in user.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw BusinessRuleViolation::make(
                code: 'not-authenticated',
                message: 'You must be signed in to change your password.',
            );
        }

        // Re-verifying the current password is what stops a hijacked session from locking the
        // real owner out of their own account.
        if (! $this->passwords->matchesCurrent($user, (string) $request->validated('current_password'))) {
            throw BusinessRuleViolation::make(
                code: 'current-password-incorrect',
                message: 'Your current password is not correct.',
            );
        }

        $this->passwords->set($user, (string) $request->validated('password'));

        // The current session is kept alive and re-stamped; every *other* session is ended.
        // Signing the user out of the tab they are actively using would be hostile.
        if (config('asids.auth.session.logout_other_devices_on_password_change') && $request->hasSession()) {
            auth()->guard('web')->logoutOtherDevices((string) $request->validated('password'));
        }

        PasswordChanged::dispatch($user, 'self-service');

        return ApiResponse::item([
            'changed' => true,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
        ]);
    }
}
