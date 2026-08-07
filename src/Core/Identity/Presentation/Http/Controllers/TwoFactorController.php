<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Application\Services\TwoFactorService;
use Asids\Core\Identity\Domain\Events\TwoFactorDisabled;
use Asids\Core\Identity\Domain\Events\TwoFactorEnabled;
use Asids\Core\Identity\Domain\Exceptions\InvalidTwoFactorCode;
use Asids\Core\Identity\Presentation\Http\Middleware\EnsureTwoFactorIsConfirmed;
use Asids\Core\Identity\Presentation\Http\Requests\ConfirmTwoFactorRequest;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service two factor management.
 *
 * Enrolment is two-phase: `enrol` issues a secret and a QR code, `confirm` requires a working
 * code before the factor takes effect. A single-phase flow locks out every user whose
 * authenticator was misconfigured, and that support burden is entirely avoidable.
 */
final class TwoFactorController extends ApiController
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Begin enrolment. The secret is returned once, alongside a rendered QR code.
     */
    public function enrol(): JsonResponse
    {
        $user = $this->currentUser();

        if ($user->hasTwoFactorEnabled()) {
            throw BusinessRuleViolation::make(
                code: 'two-factor-already-enabled',
                message: 'Two factor authentication is already active. Disable it first if you want to enrol a new device.',
            );
        }

        $enrolment = $this->twoFactor->beginEnrolment($user);

        return ApiResponse::item([
            // Shown so a user whose camera cannot read the code can type it in.
            'secret' => $enrolment['secret'],
            'otpauth_uri' => $enrolment['otpauth_uri'],
            'qr_code_svg' => $enrolment['qr_svg'],
            'digits' => config('asids.auth.two_factor.digits'),
            'period' => config('asids.auth.two_factor.period'),
        ]);
    }

    /**
     * Confirm enrolment with a working code. Returns the recovery codes, shown exactly once.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $this->currentUser();

        $recoveryCodes = $this->twoFactor->confirmEnrolment(
            $user,
            (string) $request->validated('code'),
        );

        // Enrolling proves possession right now, so the step-up window opens without a second
        // prompt — otherwise a user who enrols in order to perform a sensitive action would be
        // asked for a code twice in a row.
        $this->markSessionConfirmed($request);

        TwoFactorEnabled::dispatch($user);

        return ApiResponse::item([
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
            'notice' => 'Store these recovery codes somewhere safe. They are shown only once, and each one works a single time.',
        ]);
    }

    /**
     * Prove possession of the second factor to open the step-up window.
     *
     * Called by the SPA when it receives a 428 `two-factor-confirmation-required`, after which
     * it replays the original request.
     */
    public function confirmSession(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $this->currentUser();

        if (! $user->hasTwoFactorEnabled()) {
            throw BusinessRuleViolation::make(
                code: 'two-factor-not-enabled',
                message: 'Two factor authentication is not set up on this account.',
            );
        }

        // Only a TOTP code opens the step-up window. A recovery code is the credential for
        // someone who has lost their device, and accepting it here would let a single
        // intercepted code authorise an ownership transfer.
        if (! $this->twoFactor->verifyTotp($user, (string) $request->validated('code'))) {
            throw new InvalidTwoFactorCode;
        }

        $this->markSessionConfirmed($request);

        return ApiResponse::item([
            'confirmed' => true,
            'expires_in' => (int) config('asids.auth.two_factor.confirmation_ttl', 15) * 60,
        ]);
    }

    /**
     * Turn off two factor authentication. Requires a fresh code, so a hijacked session cannot
     * remove the control that would have stopped it.
     */
    public function destroy(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $this->currentUser();

        if ((bool) config('asids.auth.two_factor.enforced')) {
            throw BusinessRuleViolation::make(
                code: 'two-factor-mandatory',
                message: 'This workspace requires two factor authentication, so it cannot be turned off.',
            );
        }

        if (! $this->twoFactor->verifyTotp($user, (string) $request->validated('code'))) {
            throw new InvalidTwoFactorCode;
        }

        $this->twoFactor->disable($user);

        TwoFactorDisabled::dispatch($user, $user);

        return ApiResponse::item(['enabled' => false]);
    }

    /**
     * Issue a fresh set of recovery codes, invalidating the previous set.
     */
    public function regenerateRecoveryCodes(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $this->currentUser();

        if (! $this->twoFactor->verifyTotp($user, (string) $request->validated('code'))) {
            throw new InvalidTwoFactorCode;
        }

        return ApiResponse::item([
            'recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user),
            'notice' => 'Your previous recovery codes no longer work.',
        ]);
    }

    /**
     * How many recovery codes remain. Surfaced so the UI can prompt a regeneration before the
     * user runs out — discovering you have none left at the moment you need one is the worst
     * possible time.
     */
    public function status(): JsonResponse
    {
        $user = $this->currentUser();

        return ApiResponse::item([
            'enabled' => $user->hasTwoFactorEnabled(),
            'enrolled_at' => $user->two_factor_enrolled_at?->toIso8601String(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'unused_recovery_codes' => $user->hasTwoFactorEnabled()
                ? $this->twoFactor->unusedRecoveryCodeCount($user)
                : 0,
            'required_by_workspace' => (bool) config('asids.auth.two_factor.enforced'),
        ]);
    }

    private function markSessionConfirmed(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(
                EnsureTwoFactorIsConfirmed::SESSION_KEY,
                now()->getTimestamp(),
            );
        }
    }
}
