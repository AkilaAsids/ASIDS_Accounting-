# Local development

## Prerequisites

None of these are installed by the repository. On macOS:

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

```bash
brew install php@8.4 composer node@22 && brew install --cask docker
```

Verify before continuing — a mismatched PHP will fail in confusing ways:

```bash
php -v && composer -V && node -v && docker compose version
```

## First run

```bash
cp .env.example .env && composer install && npm ci && php artisan key:generate
```

`APP_KEY` is not optional and not cosmetic: it encrypts every stored two factor secret and
signs every invitation and password reset link. A missing key fails
`asids:security-check`.

Wildcard DNS for tenant subdomains does not exist on a laptop, so add the hosts you need:

```bash
sudo sh -c 'printf "127.0.0.1 asids.localhost demo.asids.localhost\n" >> /etc/hosts'
```

Bring up the stack. Postgres runs its bootstrap script on first start only — it creates the
`asids_app` role that makes row level security enforceable, so if you ever `docker compose
down -v`, it re-runs.

```bash
docker compose up -d
```

```bash
php artisan migrate --seed
```

`--seed` is worth watching. `DemoWorkspaceSeeder` builds its data by calling the real
provisioning services rather than inserting rows, so it is an end-to-end check that all seven
modules agree with each other. If they do not, seeding fails loudly here rather than a test
finding out later.

Then confirm the deployment's assumptions actually hold:

```bash
php artisan asids:security-check
```

| Service | Address |
| --- | --- |
| Central domain (sign-up) | http://asids.localhost |
| Demo workspace | http://demo.asids.localhost |
| Vite dev server | http://localhost:5173 |
| Horizon | http://asids.localhost/ops/horizon |
| Mailpit (all outbound mail) | http://localhost:8025 |
| Meilisearch | http://localhost:7700 |

## Demo accounts

Created by the seeder in local, staging and testing only. It **throws** in any other
environment — the password below is publicly documented, and a warning would be too easy to
blow past with `--force` during an incident.

| Account | Role |
| --- | --- |
| `owner@demo.test` | Owner — full control |
| `admin@demo.test` | Administrator — users, roles, companies |
| `accountant@demo.test` | Accountant — books and audit trail |
| `bookkeeper@demo.test` | Bookkeeper — day-to-day entry |
| `viewer@demo.test` | Viewer — read only |

Password for all five: `Asids#Demo2026!`

Sign in as each in turn to see the permission model working — the sidebar, the action buttons
and the settings tabs all change.

## Working without hosts entries

API clients can name the workspace in a header instead of the hostname, which is also how a
mobile app or an integration authenticates:

```bash
curl -s http://localhost/api/v1/auth/session -H 'X-Tenant: demo' -H 'Accept: application/json'
```

## Quality gates

Run what CI runs, before pushing:

```bash
composer check
```

That is Pint (`--test`), PHPStan level 8 and Pest. Front end separately:

```bash
npm run typecheck && npm run lint && npm run test && npm run build
```

## Common tasks

```bash
php artisan asids:sync-permissions --refresh-roles
```

Reconciles the capability catalogue after adding a permission in code, and grants additions to
existing workspaces' system roles. Run it after every `migrate` — the release pipeline does.

```bash
php artisan asids:audit-seal && php artisan asids:audit-verify
```

Seals the audit hash chain and verifies it. The scheduler does both automatically; run them by
hand after experimenting with audited data.

```bash
php artisan tinker --execute="dd(app(\Asids\Core\Tenancy\Application\Services\TenantContext::class)->current());"
```

Console commands start with **no** tenant context, and the Eloquent scope fails closed — so a
query that returns nothing in `tinker` is usually correct behaviour, not a bug. Wrap work in
`TenantContext::runFor()`.

## Troubleshooting

**Every query returns nothing in a console command.** Expected: no tenant context means only
NULL-tenant rows are visible. Use `TenantContext::runFor($tenant, fn () => …)`.

**`asids:security-check` reports row level security is not in force.** `DB_USERNAME` is the
schema owner. It must be `asids_app`, which the Postgres bootstrap creates as `NOBYPASSRLS`.
Recreate the volume if the script never ran: `docker compose down -v && docker compose up -d`.

**Writes fail with 419 after the tab has been open a while.** Expected once — the client
re-fetches the CSRF cookie and retries. Repeated 419s mean `SESSION_DOMAIN` does not match the
host you are browsing (it must be `.asids.localhost`, with the leading dot).

**A tenant subdomain 404s.** Missing `/etc/hosts` entry, or the slug is in
`asids.tenancy.reserved_slugs`.

**Invitation e-mails do not arrive.** They are in Mailpit, not your inbox. `MAIL_MAILER=log`
writes them to `storage/logs` instead — check which you have.

**Xdebug is slow.** It is installed but inert. Enable per command:
`XDEBUG_MODE=debug docker compose up app`.
