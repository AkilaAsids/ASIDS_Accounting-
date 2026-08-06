<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Application\Services\AuthenticationService;
use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Presentation\Http\Middleware\EnsureSessionIsCurrent;
use Asids\Core\Identity\Presentation\Http\Requests\LoginRequest;
use Asids\Core\Identity\Presentation\Http\Requests\TwoFactorChallengeRequest;
use Asids\Core\Identity\Presentation\Http\Resources\UserResource;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Sign in, second-factor challenge, sign out, and the session bootstrap the SPA calls on
 * load.
 *
 * Extends the framework controller rather than ApiController: most of these endpoints are
 * reached with no authenticated user by definition.
 */
final class AuthenticationController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly UserService $users,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Step one of sign-in: verify the password.
     *
     * Returns either an authenticated session or a two factor challenge. Both are 200 — the
     * challenge is a successful outcome of a correct password, not an error, and returning
     * 401 for it would make the SPA's error handling fight its happy path.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->attempt(
            request: $request,
            email: (string) $request->validated('email'),
            password: (string) $request->validated('password'),
            remember: (bool) $request->validated('remember', false),
        );

        if ($result['status'] === 'two_factor_required') {
            return ApiResponse::item([
                'two_factor_required' => true,
                'challenge' => $result['challenge'],
                'expires_in' => $result['expires_in'],
            ]);
        }

        return ApiResponse::item(
            data: $this->sessionPayload($request, $result['user']),
            meta: ['two_factor_required' => false],
        );
    }

    /**
     * Step two: verify the second factor and complete sign-in.
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $user = $this->auth->completeTwoFactorChallenge(
            request: $request,
            challenge: (string) $request->validated('challenge'),
            code: (string) $request->validated('code'),
            trustDevice: (bool) $request->validated('trust_device', false),
        );

        return ApiResponse::item($this->sessionPayload($request, $user));
    }

    /**
     * Everything the SPA needs to render the shell in one call.
     *
     * Deliberately one request rather than four (user, permissions, companies, workspace): the
     * shell cannot render until all of them have arrived, so splitting them only adds
     * round-trip latency on the most latency-sensitive screen in the product.
     */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::item(['authenticated' => false]);
        }

        $this->users->touchActivity($user);

        return ApiResponse::item($this->sessionPayload($request, $user));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->signOut($request);

        return ApiResponse::item(['authenticated' => false]);
    }

    /**
     * Sign out of every session, everywhere.
     *
     * Bumping the per-user epoch is what makes this immediate under the Redis session driver,
     * where sessions cannot be enumerated by user.
     */
    public function logoutEverywhere(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            cache()->forever(UserService::sessionEpochKey($user), (string) Str::uuid7());
        }

        $this->auth->signOut($request);

        return ApiResponse::item(['authenticated' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(Request $request, User $user): array
    {
        // Stamped so EnsureSessionIsCurrent can detect a later revocation without needing to
        // enumerate sessions.
        if ($request->hasSession()) {
            $epoch = cache()->get(UserService::sessionEpochKey($user));

            if (is_string($epoch)) {
                $request->session()->put(EnsureSessionIsCurrent::SESSION_EPOCH, $epoch);
            }
        }

        $user->load([
            'roles:id,name,label,level,is_owner',
            'companies:id,name,code,base_currency_code,currency_precision,timezone',
            'defaultCompany:id,name,code',
        ]);

        $tenant = $this->tenantContext->current();

        return [
            'authenticated' => true,
            'user' => (new UserResource($user))->resolve($request),

            // Sent once, so the SPA can hide controls the user cannot use without asking the
            // server per button. The server still authorises every request — this is for
            // presentation only, and the front end is written on that assumption.
            'permissions' => $user->permissionNames(),

            'workspace' => $tenant === null ? null : [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'locale' => $tenant->locale,
                'timezone' => $tenant->timezone,
                'currency_code' => $tenant->currency_code,
                'country_code' => $tenant->country_code,
                'on_trial' => $tenant->isOnTrial(),
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            ],

            'companies' => $user->companies->map(static fn ($company): array => [
                'id' => $company->getKey(),
                'name' => $company->name,
                'code' => $company->code,
                'base_currency_code' => $company->base_currency_code,
                'currency_precision' => $company->currency_precision,
                'timezone' => $company->timezone,
                'is_default' => (bool) $company->getAttribute('pivot')?->is_default,
            ])->values()->all(),

            // Client-side routing decisions the server already knows the answer to. Sending
            // them avoids the SPA discovering a required interstitial by receiving a 428 on a
            // screen it has already begun rendering.
            'requires' => [
                'password_change' => $user->passwordHasExpired(),
                'two_factor_enrolment' => (bool) config('asids.auth.two_factor.enforced')
                    && ! $user->hasTwoFactorEnabled(),
                'company_selection' => $user->companies->count() > 1
                    && $user->default_company_id === null,
            ],
        ];
    }
}
