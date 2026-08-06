<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASIDS ERP Cloud — platform configuration
|--------------------------------------------------------------------------
|
| Every cross-cutting platform policy lives here rather than being scattered
| through the modules, so that a security or compliance reviewer has a single
| authoritative file to inspect and an operator has a single file to tune.
|
| Framework and package configuration is intentionally NOT duplicated in this
| repository. Laravel 12 falls back to the defaults bundled with the framework
| when a config file is absent, so only files we genuinely deviate from are
| committed. See docs/adr/0004-minimal-config-surface.md.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    | Comma separated list of load balancer addresses, or "*" when the app is
    | only ever reachable through a trusted ALB. Leaving this empty means client
    | IP addresses in the audit trail come from the socket, not a spoofable
    | header.
    */
    'trusted_proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        static fn (string $proxy): bool => $proxy !== '',
    )) ?: null,

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    | ASIDS uses single-database, row-scoped multi-tenancy. See
    | docs/adr/0001-tenancy-strategy.md for the reasoning and the migration
    | path to a dedicated database for enterprise customers.
    */
    'tenancy' => [
        // Host on which the marketing site and the central admin live. Tenant
        // subdomains are resolved beneath it: acme.erp.asidstech.com
        'central_domain' => env('TENANCY_CENTRAL_DOMAIN', 'localhost'),

        // header | subdomain | both
        'identification' => env('TENANCY_IDENTIFICATION', 'both'),

        // Name of the HTTP header carrying the tenant slug for API clients.
        'header' => 'X-Tenant',

        // Enforce PostgreSQL row level security in addition to the Eloquent
        // global scope. Requires DB_USERNAME to be a NOBYPASSRLS role.
        'enforce_rls' => (bool) env('TENANCY_ENFORCE_RLS', true),

        // PostgreSQL session variable read by every RLS policy.
        'rls_session_variable' => 'asids.tenant_id',

        // Subdomains that can never belong to a tenant.
        'reserved_slugs' => [
            'admin', 'api', 'app', 'assets', 'billing', 'blog', 'cdn', 'central',
            'dashboard', 'dev', 'docs', 'help', 'mail', 'ops', 'partner', 'public',
            'root', 'shop', 'signup', 'staging', 'static', 'status', 'support',
            'system', 'test', 'www',
        ],

        // Cache TTL, in seconds, for slug/domain to tenant resolution.
        'resolution_cache_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication & password policy
    |--------------------------------------------------------------------------
    | Defaults are aligned with NIST SP 800-63B: length over composition, plus
    | breach checking. Tenants may tighten (never loosen) these through the
    | Settings module.
    */
    'auth' => [
        'password' => [
            'min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),
            'require_mixed_case' => true,
            'require_numbers' => true,
            'require_symbols' => true,
            // Reject passwords appearing in the Have I Been Pwned corpus.
            'check_compromised' => (bool) env('AUTH_PASSWORD_CHECK_COMPROMISED', true),
            // Number of previous hashes retained to prevent reuse.
            'history' => (int) env('AUTH_PASSWORD_HISTORY', 5),
            // Days before a password must be rotated. 0 disables expiry.
            'expires_after_days' => (int) env('AUTH_PASSWORD_EXPIRY_DAYS', 180),
        ],

        'lockout' => [
            'max_attempts' => (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
            'decay_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
            // Throttle by e-mail *and* IP so one attacker cannot lock out a
            // whole tenant, and one victim cannot be brute forced from a farm.
            'throttle_by' => ['email', 'ip'],
        ],

        'two_factor' => [
            // When true, every user must enrol before reaching any other route.
            'enforced' => (bool) env('AUTH_TWO_FACTOR_ENFORCED', false),
            'issuer' => env('APP_NAME', 'ASIDS ERP Cloud'),
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'sha1',   // Required for Google Authenticator compat.
            'window' => 1,           // Accept one step of clock drift each way.
            'secret_length' => 32,
            'recovery_code_count' => 8,
            'recovery_code_bytes' => 10,
            // Minutes a "two factor confirmed" marker stays valid for
            // step-up-protected actions such as changing bank details.
            'confirmation_ttl' => 15,
        ],

        'session' => [
            // Minutes of inactivity before the SPA session is invalidated.
            'idle_timeout' => (int) env('AUTH_SESSION_IDLE_TIMEOUT', 30),
            // Terminate other sessions when a password changes.
            'logout_other_devices_on_password_change' => true,
            'max_concurrent_sessions' => (int) env('AUTH_MAX_CONCURRENT_SESSIONS', 0),
        ],

        'tokens' => [
            // Default abilities granted to a personal access token when none are
            // requested. Deliberately empty: tokens must opt in explicitly.
            'default_abilities' => [],
            'max_per_user' => 25,
            'default_expiry_days' => 365,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    | Audit rows are append-only and linked by a SHA-256 hash chain, so any
    | tampering with history is detectable. Retention defaults to seven years
    | to satisfy Sri Lankan record-keeping expectations for accounting records.
    */
    'audit' => [
        'hash_chain' => (bool) env('AUDIT_HASH_CHAIN_ENABLED', true),
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 2555),
        // The activity feed is a dashboard surface, not a record, so it is kept for months
        // rather than years.
        'activity_retention_days' => (int) env('ACTIVITY_RETENTION_DAYS', 90),
        'queue' => env('AUDIT_QUEUE', 'audit'),
        // Attribute names whose values are replaced with a redaction marker
        // before ever being written to the audit trail.
        'redacted_attributes' => [
            'password', 'password_confirmation', 'current_password',
            'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
            'token', 'access_token', 'refresh_token', 'api_key', 'secret',
            'card_number', 'cvv', 'nic', 'tin', 'bank_account_number',
        ],
        'redaction_marker' => '[redacted]',
    ],

    /*
    |--------------------------------------------------------------------------
    | API surface
    |--------------------------------------------------------------------------
    */
    'api' => [
        'version' => 'v1',
        'pagination' => [
            'default_per_page' => 25,
            'max_per_page' => 200,
        ],
        'rate_limits' => [
            // requests per minute
            'authenticated' => (int) env('API_RATE_LIMIT_AUTHENTICATED', 300),
            'guest' => (int) env('API_RATE_LIMIT_GUEST', 60),
            'login' => (int) env('API_RATE_LIMIT_LOGIN', 10),
            'two_factor' => (int) env('API_RATE_LIMIT_TWO_FACTOR', 10),
            'password_reset' => (int) env('API_RATE_LIMIT_PASSWORD_RESET', 5),
            'export' => (int) env('API_RATE_LIMIT_EXPORT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localisation & regional defaults
    |--------------------------------------------------------------------------
    | Phase 1 only needs the defaults a newly provisioned tenant inherits. The
    | Sri Lankan compliance pack that consumes these lands in a later phase and
    | is deliberately swappable per country.
    */
    'regional' => [
        'default_country' => env('ASIDS_DEFAULT_COUNTRY', 'LK'),
        'default_currency' => env('ASIDS_DEFAULT_CURRENCY', 'LKR'),
        'default_timezone' => env('ASIDS_DEFAULT_TIMEZONE', 'Asia/Colombo'),
        'default_locale' => env('ASIDS_DEFAULT_LOCALE', 'en'),
        'supported_locales' => ['en', 'si', 'ta'],
        // Registered compliance packs, keyed by ISO 3166-1 alpha-2.
        'compliance_packs' => [
            'LK' => \Asids\Core\Platform\Support\NullCompliancePack::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform limits
    |--------------------------------------------------------------------------
    | Guard rails that keep one tenant from degrading the platform for others.
    */
    'limits' => [
        'max_companies_per_tenant' => (int) env('ASIDS_MAX_COMPANIES', 50),
        'max_branches_per_company' => (int) env('ASIDS_MAX_BRANCHES', 100),
        'max_users_per_tenant' => (int) env('ASIDS_MAX_USERS', 500),
        'max_upload_size_kb' => (int) env('ASIDS_MAX_UPLOAD_KB', 32768),
    ],
];
