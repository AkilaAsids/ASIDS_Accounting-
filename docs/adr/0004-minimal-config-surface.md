# ADR 0004 — Commit only the configuration files we deviate from

- **Status:** Accepted
- **Date:** 2026-08-06

## Context

Laravel 12 resolves configuration from the framework's own bundled defaults when a
file is absent from `config/`. A project can therefore commit all ~15 standard config
files, or only those it genuinely changes.

## Decision

Commit only the files that deviate from the framework default:

| File | Why it exists |
| --- | --- |
| `asids.php` | The platform's own domain configuration. New. |
| `database.php` | PostgreSQL specifics, the `pgsql_admin` connection, separate Redis databases for cache and queue. |
| `tenancy.php` | Package configuration; single-database mode and our bootstrappers. |
| `permission.php` | Package configuration; teams mode keyed on `tenant_id`. |
| `sanctum.php` | Token prefix, hard expiry, custom token model. |
| `horizon.php` | Queue supervisors partitioned by service-level objective. |
| `logging.php` | Separate `security` and `audit` channels, tenant-context tap. |
| `filesystems.php` | Private-by-default disks, KMS encryption, `documents` and `backups` disks. |
| `cors.php` | Credentialed cross-origin requests for the SPA; wildcard tenant subdomain pattern. |

Everything else — `app.php`, `auth.php`, `cache.php`, `mail.php`, `queue.php`,
`session.php`, `services.php`, `view.php`, `scout.php` — is driven entirely from
`.env` and inherits the framework default.

## Rationale

A committed config file is a permanent maintenance obligation: when the framework
changes a default or adds a key, a vendored copy silently keeps the old behaviour and
misses the new option. On a codebase intended to live for a decade across many
major-version upgrades, nine files of genuine deviation are reviewable; twenty-four
files where fifteen are unmodified copies are not — a reviewer cannot tell which
values were chosen and which were merely inherited.

The one real cost is discoverability: a developer looking for `session.lifetime` will
not find `config/session.php`. That is addressed by documenting every environment
variable in `.env.example` with its meaning, which is where an operator looks anyway.

## Consequences

- `php artisan config:publish <name>` publishes a file the moment a real deviation is
  needed; nothing is lost.
- Framework upgrades pick up new defaults automatically for everything we have not
  deliberately overridden.
- `config/asids.php` becomes the single file a security or compliance reviewer must
  read to understand platform policy, which is the intended outcome.
