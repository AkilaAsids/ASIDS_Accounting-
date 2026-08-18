<?php

declare(strict_types=1);

use Asids\Core\Accounting\Presentation\Http\Controllers\AccountController;
use Asids\Core\Accounting\Presentation\Http\Controllers\FiscalPeriodController;
use Asids\Core\Accounting\Presentation\Http\Controllers\JournalEntryController;
use Asids\Core\Accounting\Presentation\Http\Controllers\LedgerReportController;
use Asids\Core\Audit\Presentation\Http\Controllers\ActivityLogController;
use Asids\Core\Audit\Presentation\Http\Controllers\AuditLogController;
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
use Asids\Core\Sales\Presentation\Http\Controllers\CustomerController;
use Asids\Core\Sales\Presentation\Http\Controllers\ReceivableReportController;
use Asids\Core\Sales\Presentation\Http\Controllers\TaxCodeController;
use Asids\Core\Settings\Presentation\Http\Controllers\SettingsController;
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

    Route::middleware(['auth:sanctum', 'session.current'])->group(function (): void {

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
            | Settings
            |------------------------------------------------------------
            |
            | `bootstrap` is outside the permission checks by design: the interface cannot
            | render a date without the locale settings, so withholding them from a user who
            | lacks the settings permission would leave them looking at raw timestamps.
            */
            Route::prefix('settings')->name('settings.')->group(function (): void {
                Route::get('bootstrap', [SettingsController::class, 'bootstrap'])->name('bootstrap');
                Route::get('/', [SettingsController::class, 'index'])->name('index');
                Route::put('/', [SettingsController::class, 'update'])->name('update');
                Route::delete('{key}', [SettingsController::class, 'reset'])
                    ->where('key', '[a-z0-9_.]+')
                    ->name('reset');
            });

            /*
            |------------------------------------------------------------
            | Audit trail and activity feed
            |------------------------------------------------------------
            */
            Route::prefix('audit')->name('audit.')->group(function (): void {
                Route::get('/', [AuditLogController::class, 'index'])->name('index');

                // The morph alias, not a class name, so the URL cannot be used to probe for
                // internal namespaces.
                Route::get('records/{type}/{id}', [AuditLogController::class, 'forRecord'])
                    ->where(['type' => '[a-z_]+', 'id' => '[0-9a-fA-F-]{36}'])
                    ->name('for-record');

                // Step-up protected: the result reveals whether the trail has been tampered
                // with, which is information an attacker would very much like.
                Route::post('verify', [AuditLogController::class, 'verify'])
                    ->middleware('two-factor')
                    ->name('verify');
            });

            Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

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

                /*
                 * The `company` middleware belongs on these two groups and not on the workspace-level
                 * routes above.
                 *
                 * It verifies that the caller is a member of the company in the path and publishes it
                 * to `RequestContext`, which is what puts `company_id` on every audit entry the
                 * request writes. Until it was applied here it was registered as an alias and used by
                 * no route at all: `ResolveActiveCompany` was the only caller of
                 * `RequestContext::setCompanyId()`, so company attribution was absent from the whole
                 * audit trail and the SPA's `X-Company` header was silently ignored.
                 *
                 * Not applied to `companies` itself, `{company}/select`, or anything workspace-level:
                 * a user who has been invited but not yet granted access to any company must still be
                 * able to read their profile, list the companies they might be given, and sign out.
                 */
                Route::prefix('{company}/branches')->name('branches.')->middleware('company')->group(function (): void {
                    Route::get('/', [BranchController::class, 'index'])->name('index');
                    Route::post('/', [BranchController::class, 'store'])->name('store');
                    Route::get('{branch}', [BranchController::class, 'show'])->name('show');
                    Route::put('{branch}', [BranchController::class, 'update'])->name('update');
                    Route::post('{branch}/archive', [BranchController::class, 'archive'])->name('archive');
                    Route::post('{branch}/restore', [BranchController::class, 'restore'])->name('restore');
                    Route::post('{branch}/make-primary', [BranchController::class, 'makePrimary'])->name('make-primary');
                });

                /*
                 * ── Accounting ──────────────────────────────────────────────
                 *
                 * Everything is nested under a company because a ledger belongs to one legal entity,
                 * and a flat route would invite a query that forgets to scope by it — which in a
                 * workspace holding several companies puts one entity's figures in another's report.
                 *
                 * Posting and reversing are their own endpoints rather than a status field on an
                 * update. They are different capabilities held by different people, and a PATCH that
                 * could set `status: posted` would make the bookkeeper/accountant split a matter of
                 * what the client chose to send.
                 */
                Route::prefix('{company}/accounts')->name('accounts.')->middleware('company')->group(function (): void {
                    Route::get('/', [AccountController::class, 'index'])->name('index');
                    Route::post('/', [AccountController::class, 'store'])->name('store');
                    Route::get('template', [AccountController::class, 'template'])->name('template');
                    Route::post('template', [AccountController::class, 'applyTemplate'])->name('template.apply');
                    Route::get('{account}', [AccountController::class, 'show'])->name('show');
                    Route::put('{account}', [AccountController::class, 'update'])->name('update');
                    Route::delete('{account}', [AccountController::class, 'destroy'])->name('destroy');
                    Route::post('{account}/archive', [AccountController::class, 'archive'])->name('archive');
                    Route::post('{account}/restore', [AccountController::class, 'restore'])->name('restore');
                    Route::get('{account}/ledger', [LedgerReportController::class, 'accountLedger'])->name('ledger');
                });

                Route::prefix('{company}/journal-entries')->name('journal-entries.')->middleware('company')->group(function (): void {
                    Route::get('/', [JournalEntryController::class, 'index'])->name('index');
                    Route::post('/', [JournalEntryController::class, 'store'])->name('store');
                    Route::get('{entry}', [JournalEntryController::class, 'show'])->name('show');
                    Route::put('{entry}', [JournalEntryController::class, 'update'])->name('update');
                    Route::delete('{entry}', [JournalEntryController::class, 'destroy'])->name('destroy');
                    Route::post('{entry}/post', [JournalEntryController::class, 'post'])->name('post');
                    Route::post('{entry}/reverse', [JournalEntryController::class, 'reverse'])->name('reverse');
                });

                Route::prefix('{company}/fiscal-calendar')->name('fiscal-calendar.')->middleware('company')->group(function (): void {
                    Route::get('/', [FiscalPeriodController::class, 'index'])->name('index');
                    Route::post('years', [FiscalPeriodController::class, 'openYear'])->name('years.open');
                    Route::get('years/{year}/result', [FiscalPeriodController::class, 'yearResult'])->name('years.result');
                    Route::post('years/{year}/close', [FiscalPeriodController::class, 'closeYear'])->name('years.close');
                    Route::post('periods/{period}/close', [FiscalPeriodController::class, 'closePeriod'])->name('periods.close');
                    Route::post('periods/{period}/reopen', [FiscalPeriodController::class, 'reopenPeriod'])->name('periods.reopen');
                });

                Route::prefix('{company}/reports')->name('reports.')->middleware('company')->group(function (): void {
                    Route::get('trial-balance', [LedgerReportController::class, 'trialBalance'])->name('trial-balance');
                });

                /*
                 * ── Sales: customers ────────────────────────────────────────
                 *
                 * A customer is company-owned for the same reason the chart of accounts is: the
                 * receivable a customer's invoices post to belongs to one set of books, and a flat
                 * route would invite a query that forgets to scope by company.
                 */
                Route::prefix('{company}/customers')->name('customers.')->middleware('company')->group(function (): void {
                    Route::get('/', [CustomerController::class, 'index'])->name('index');
                    Route::post('/', [CustomerController::class, 'store'])->name('store');
                    Route::get('{customer}', [CustomerController::class, 'show'])->name('show');
                    Route::put('{customer}', [CustomerController::class, 'update'])->name('update');
                    Route::delete('{customer}', [CustomerController::class, 'destroy'])->name('destroy');
                    Route::post('{customer}/archive', [CustomerController::class, 'archive'])->name('archive');
                    // `withTrashed()` is route-wide, so it also lifts the soft-delete scope for the
                    // bound `{company}` (which uses SoftDeletes) — intended only for the `{customer}`
                    // binding, so a soft-deleted customer can still be restored. Not exploitable: the
                    // `company` middleware's `ResolveActiveCompany` independently re-resolves the url
                    // company with its default scopes plus `active()` and membership, so a trashed
                    // company still fails closed with a 404 regardless of this route's binding.
                    Route::post('{customer}/restore', [CustomerController::class, 'restore'])->name('restore')->withTrashed();
                    Route::post('{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('deactivate');
                    Route::post('{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('reactivate');
                });

                /*
                 * ── Sales: tax codes ────────────────────────────────────────
                 *
                 * A company's tax configuration is company-owned for the same reason the chart of
                 * accounts is: the rate an invoice charges belongs to one set of books, and a flat
                 * route would invite a query that forgets to scope by company.
                 */
                Route::prefix('{company}/tax-codes')->name('tax-codes.')->middleware('company')->group(function (): void {
                    Route::get('/', [TaxCodeController::class, 'index'])->name('index');
                    Route::post('/', [TaxCodeController::class, 'store'])->name('store');
                    Route::get('{taxCode}', [TaxCodeController::class, 'show'])->name('show');
                    Route::put('{taxCode}', [TaxCodeController::class, 'update'])->name('update');
                    Route::delete('{taxCode}', [TaxCodeController::class, 'destroy'])->name('destroy');
                    Route::post('{taxCode}/end-range', [TaxCodeController::class, 'endRange'])->name('end-range');
                    Route::post('{taxCode}/deactivate', [TaxCodeController::class, 'deactivate'])->name('deactivate');
                    Route::post('{taxCode}/reactivate', [TaxCodeController::class, 'reactivate'])->name('reactivate');
                    // `withTrashed()` is route-wide, so it also lifts the soft-delete scope for the
                    // bound `{company}` (which uses SoftDeletes) — intended only for the `{taxCode}`
                    // binding, so a soft-deleted tax code can still be restored. Not exploitable: the
                    // `company` middleware's `ResolveActiveCompany` independently re-resolves the url
                    // company with its default scopes plus `active()` and membership, so a trashed
                    // company still fails closed with a 404 regardless of this route's binding.
                    Route::post('{taxCode}/restore', [TaxCodeController::class, 'restore'])->name('restore')->withTrashed();
                });

                /*
                 * ── Sales: receivables reporting ────────────────────────────
                 *
                 * Nested under the company for the same reason the trial balance is: a receivables figure
                 * belongs to one legal entity, and a flat route would invite a query that forgets to scope by
                 * it — which in a workspace holding several companies puts one entity's debtors in another's
                 * report.
                 *
                 * A second group sharing the `{company}/reports` prefix with the Accounting one above, rather
                 * than routes appended to it. The prefix and name are identical, the route names stay unique,
                 * and Sales routes stay in the Sales section of this file instead of being filed under a
                 * comment block about the ledger.
                 *
                 * All three are reads with no resource id in the path, so there is no sibling-company binding
                 * to guard — the caller names a company, the middleware proves membership of it, and
                 * `ReceivableReportService` scopes every query to it explicitly.
                 *
                 * Only `aged-receivables` takes a parameter. `ar-control` deliberately accepts no cutoff: its
                 * subledger side reads current status and `amount_due`, so a past date would have the two
                 * halves it compares answering different questions (ADR 0010 D3).
                 */
                Route::prefix('{company}/reports')->name('reports.')->middleware('company')->group(function (): void {
                    Route::get('outstanding-receivables', [ReceivableReportController::class, 'outstandingReceivables'])->name('outstanding-receivables');
                    Route::get('aged-receivables', [ReceivableReportController::class, 'agedReceivables'])->name('aged-receivables');
                    Route::get('ar-control', [ReceivableReportController::class, 'arControl'])->name('ar-control');
                });

                Route::prefix('{company}/members')->name('members.')->middleware('company')->group(function (): void {
                    Route::get('/', [CompanyMembershipController::class, 'index'])->name('index');
                    Route::post('/', [CompanyMembershipController::class, 'store'])->name('store');
                    Route::delete('{membership}', [CompanyMembershipController::class, 'destroy'])->name('destroy');
                });
            });
        });
    });
});
