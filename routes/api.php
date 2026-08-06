<?php

declare(strict_types=1);

use Asids\Core\Authorization\Presentation\Http\Controllers\PermissionController;
use Asids\Core\Authorization\Presentation\Http\Controllers\RoleController;
use Asids\Core\Identity\Presentation\Http\Controllers\AccessTokenController;
use Asids\Core\Identity\Presentation\Http\Controllers\AuthenticationController;
use Asids\Core\Identity\Presentation\Http\Controllers\DeviceController;
use Asids\Core\Identity\Presentation\Http\Controllers\LoginHistoryController;
use Asids\Core\Identity\Presentation\Http\Controllers\PasswordController;
use Asids\Core\Identity\Presentation\Http\Controllers\ProfileController;
use Asids\Core\Identity\Presentation\Http\Controllers\TwoFactorController;
use Asids\Core\Identity\Presentation\Http\Controllers\UserController;
use Asids\Core\Organization\Presentation\Http\Controllers\BranchController;
use Asids\Core\Organization\Presentation\Http\Controllers\CompanyController;
use Asids\Core\Organization\Presentation\Http\Controllers\CompanyMembershipController;
use Asids\Core\Tenancy\Presentation\Http\Controllers\TenantRegistrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Route names are prefixed `api.v1.` throughout, because AccountLinkService signs a
| URL by route name and a rename would silently invalidate every invitation and reset
| link already in a customer's inbox.
|
| Three middleware layers, applied in this order:
|
|   ResolveTenant       Global (bootstrap/app.php). Runs before authentication because
|                       `users` is tenant scoped — the guard cannot look up the
|                       authenticating user until the workspace is known.
|   auth:sanctum        Cookie session for the SPA, bearer token for integrations.
|   password.fresh      Confines a user with an expired password to the endpoints that
|                       let them fix it.
|
| `two-factor` is applied per route rather than per group: it is step-up
| authentication, and demanding it on every request would be unusable.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Public — no authentication
    |----------------------------------------------------------------------
    */

    // Workspace sign-up. Throttled with the credential limiter rather than the general
    // one: this endpoint creates real rows, so an unthrottled loop is a denial of
    // service against the tenants table.
    Route::post('workspaces', [TenantRegistrationController::class, 'store'])
        ->middleware('throttle:login')
        ->name('workspaces.store');

    Route::get('workspaces/availability', [TenantRegistrationController::class, 'checkAvailability'])
        ->middleware('throttle:login')
        ->name('workspaces.availability');

    /*
    |----------------------------------------------------------------------
    | Authentication
    |----------------------------------------------------------------------
    */

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('login', [AuthenticationController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        Route::post('two-factor-challenge', [AuthenticationController::class, 'twoFactorChallenge'])
            ->middleware('throttle:two-factor')
            ->name('two-factor-challenge');

        Route::post('forgot-password', [PasswordController::class, 'forgot'])
            ->middleware('throttle:password-reset')
            ->name('forgot-password');
    });

    /*
    |----------------------------------------------------------------------
    | Signed account links — invitations and password resets
    |----------------------------------------------------------------------
    |
    | Both verbs share one path deliberately. A signature covers the URL string, not the
    | HTTP method, so the link AccountLinkService signs against `account-link.consume`
    | validates on the GET too — which lets the SPA inspect a link (whose account? which
    | password policy?) before asking the user to type anything, without a second
    | signature.
    */
    Route::middleware(['signed', 'throttle:password-reset'])->group(function (): void {
        Route::get('account-link/{user}', [PasswordController::class, 'inspectLink'])
            ->name('account-link.inspect');

        Route::post('account-link/{user}', [PasswordController::class, 'consumeLink'])
            ->name('account-link.consume');
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated
    |----------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function (): void {

        // ── Reachable with an expired password ───────────────────────────
        // A user whose password has expired is authenticated but confined. These four
        // endpoints sit outside `password.fresh` so they can actually comply.
        Route::get('auth/session', [AuthenticationController::class, 'session'])->name('auth.session');
        Route::post('auth/logout', [AuthenticationController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-everywhere', [AuthenticationController::class, 'logoutEverywhere'])->name('auth.logout-everywhere');
        Route::put('auth/password', [PasswordController::class, 'change'])->name('auth.password.change');

        // Two factor enrolment is also outside the fence: a workspace that mandates 2FA
        // must let a confined user enrol, or they can never proceed.
        Route::prefix('auth/two-factor')->name('auth.two-factor.')->group(function (): void {
            Route::get('/', [TwoFactorController::class, 'status'])->name('status');
            Route::post('enrol', [TwoFactorController::class, 'enrol'])->name('enrol');
            Route::post('confirm', [TwoFactorController::class, 'confirm'])
                ->middleware('throttle:two-factor')
                ->name('confirm');
            Route::post('confirm-session', [TwoFactorController::class, 'confirmSession'])
                ->middleware('throttle:two-factor')
                ->name('confirm-session');
            Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
                ->middleware('throttle:two-factor')
                ->name('recovery-codes');
            Route::delete('/', [TwoFactorController::class, 'destroy'])
                ->middleware('throttle:two-factor')
                ->name('destroy');
        });

        // ── Everything else requires a current password ──────────────────
        Route::middleware('password.fresh')->group(function (): void {

            /*
            |------------------------------------------------------------
            | Self service
            |------------------------------------------------------------
            */
            Route::prefix('me')->name('me.')->group(function (): void {
                Route::get('/', [ProfileController::class, 'show'])->name('show');
                Route::put('/', [ProfileController::class, 'update'])->name('update');

                Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
                Route::get('login-history', [LoginHistoryController::class, 'mine'])->name('login-history');
            });

            // Revoking a device is self-service or administrative depending on whose it
            // is, so the policy decides rather than the route.
            Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

            /*
            |------------------------------------------------------------
            | API tokens
            |------------------------------------------------------------
            */
            Route::prefix('tokens')->name('tokens.')->group(function (): void {
                Route::get('/', [AccessTokenController::class, 'index'])->name('index');

                // Step-up protected: a token is a long-lived credential, so a hijacked
                // session must not be enough to mint one.
                Route::post('/', [AccessTokenController::class, 'store'])
                    ->middleware('two-factor')
                    ->name('store');

                Route::delete('{token}', [AccessTokenController::class, 'destroy'])->name('destroy');
            });

            /*
            |------------------------------------------------------------
            | Users
            |------------------------------------------------------------
            */
            Route::prefix('users')->name('users.')->group(function (): void {
                Route::get('/', [UserController::class, 'index'])->name('index');
                Route::post('/', [UserController::class, 'store'])->name('store');
                Route::get('{user}', [UserController::class, 'show'])->name('show');
                Route::put('{user}', [UserController::class, 'update'])->name('update');

                Route::post('{user}/suspend', [UserController::class, 'suspend'])->name('suspend');
                Route::post('{user}/reinstate', [UserController::class, 'reinstate'])->name('reinstate');
                Route::post('{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');

                // Both produce or remove a credential on someone else's account, so both
                // demand fresh proof of the actor's own second factor.
                Route::post('{user}/send-password-reset', [UserController::class, 'sendPasswordReset'])
                    ->middleware(['two-factor', 'throttle:password-reset'])
                    ->name('send-password-reset');

                Route::delete('{user}/two-factor', [UserController::class, 'resetTwoFactor'])
                    ->middleware('two-factor')
                    ->name('reset-two-factor');

                Route::get('{user}/devices', [DeviceController::class, 'index'])->name('devices');

                // Replaces the user's whole role set. Nested under the user because it is a
                // full replacement of one person's roles, which "add user to role" cannot
                // express atomically.
                Route::put('{user}/roles', [RoleController::class, 'assign'])
                    ->middleware('two-factor')
                    ->name('roles.assign');

                Route::post('{user}/transfer-ownership', [RoleController::class, 'transferOwnership'])
                    ->middleware('two-factor')
                    ->name('transfer-ownership');
            });

            Route::get('login-history', [LoginHistoryController::class, 'index'])->name('login-history.index');

            /*
            |------------------------------------------------------------
            | Roles and permissions
            |------------------------------------------------------------
            */
            Route::apiResource('roles', RoleController::class)
                ->parameters(['roles' => 'role'])
                ->names('roles');

            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');

            /*
            |------------------------------------------------------------
            | Companies, branches, access
            |------------------------------------------------------------
            */
            Route::prefix('companies')->name('companies.')->group(function (): void {
                Route::get('/', [CompanyController::class, 'index'])->name('index');
                Route::post('/', [CompanyController::class, 'store'])->name('store');
                Route::get('{company}', [CompanyController::class, 'show'])->name('show');
                Route::put('{company}', [CompanyController::class, 'update'])->name('update');

                Route::post('{company}/archive', [CompanyController::class, 'archive'])->name('archive');
                Route::post('{company}/restore', [CompanyController::class, 'restore'])->name('restore');
                Route::post('{company}/make-default', [CompanyController::class, 'makeDefault'])->name('make-default');

                // Choosing which company *you* land in is a preference, not a privilege.
                Route::post('{company}/select', [CompanyMembershipController::class, 'setOwnDefault'])->name('select');

                Route::prefix('{company}/branches')->name('branches.')->group(function (): void {
                    Route::get('/', [BranchController::class, 'index'])->name('index');
                    Route::post('/', [BranchController::class, 'store'])->name('store');
                    Route::get('{branch}', [BranchController::class, 'show'])->name('show');
                    Route::put('{branch}', [BranchController::class, 'update'])->name('update');
                    Route::post('{branch}/archive', [BranchController::class, 'archive'])->name('archive');
                    Route::post('{branch}/restore', [BranchController::class, 'restore'])->name('restore');
                    Route::post('{branch}/make-primary', [BranchController::class, 'makePrimary'])->name('make-primary');
                });

                Route::prefix('{company}/members')->name('members.')->group(function (): void {
                    Route::get('/', [CompanyMembershipController::class, 'index'])->name('index');
                    Route::post('/', [CompanyMembershipController::class, 'store'])->name('store');
                    Route::delete('{membership}', [CompanyMembershipController::class, 'destroy'])->name('destroy');
                });
            });
        });
    });
});
