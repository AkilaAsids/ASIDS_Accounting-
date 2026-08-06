# Phase 1 security review

Reviewed by reading. **Nothing in this repository has been executed**, so no control below has
been demonstrated — this records what was designed and where the residual risk sits, not what
was proven. Items marked **UNVERIFIED** need a test before anyone relies on them.

## OWASP Top 10 (2021)

| | Risk | Treatment | State |
| --- | --- | --- | --- |
| A01 | Broken access control | Three isolation layers (Eloquent scope, FORCED RLS, per-tenant prefixing); permission + membership both required; role levels prevent escalation; policies on every model | **UNVERIFIED** |
| A02 | Cryptographic failures | 2FA secrets encrypted at the application layer; recovery codes individually SHA-256'd; tokens stored as SHA-256; S3 SSE-KMS; TLS 1.3; `SESSION_ENCRYPT=true` | Designed |
| A03 | Injection | Eloquent and bound parameters throughout; `set_config()` with bound values, never string-interpolated `SET`; `QueryCriteria` allow-lists sortable and filterable columns | Designed |
| A04 | Insecure design | Documented threat reasoning in 5 ADRs; append-only audit trail; step-up on credential-bearing routes; seams for compliance and ledger rules | Designed |
| A05 | Security misconfiguration | `asids:security-check` asserts six deployment assumptions and fails the release; `APP_DEBUG` guarded in the entrypoint; no public storage disk | **UNVERIFIED** |
| A06 | Vulnerable components | `composer audit` and `npm audit --audit-level=high` in CI; pinned major versions | Designed |
| A07 | Identification & authentication | NIST SP 800-63B password policy with breach checking; per-account lockout plus per-IP/per-identity limits; TOTP with replay resistance; session fixation defence; epoch-based revocation | **UNVERIFIED** |
| A08 | Software & data integrity | Hash-chained audit trail with database-enforced append-only; signed account links bound to credential state; `insertOrIgnore` idempotency in provisioning | **UNVERIFIED** |
| A09 | Logging & monitoring | Separate `security` and `audit` log channels; credential scrubber on every channel; `login_histories` records failures against unknown addresses; request-id correlation end to end | Designed |
| A10 | SSRF | No user-supplied URL is fetched anywhere in Phase 1 | N/A |

## Controls, and what each actually defends against

### Tenant isolation

`TenantScope` **fails closed**: with no tenant context, only NULL-tenant rows are visible. The
tempting alternative — return everything when unscoped — turns every console command and
forgotten middleware into a cross-tenant leak.

RLS is FORCED, so it applies even to the table owner. Without `FORCE`, a developer connecting as
the schema owner runs with protection silently disabled and the isolation tests pass without
testing anything.

**Stated limit.** The tenant travels in a session variable the application role sets itself, so
an attacker with arbitrary SQL as that role can also set it. RLS here guards against *our bugs*,
not a compromised credential. That is the standard trade for single-database tenancy and is why
credential protection is treated as a first-class control. Customers whose regulator requires
cryptographic separation go on the dedicated-database tier.

`RowLevelSecurity::bypass()` is the sole escape hatch: greppable, `finally`-scoped so an
exception cannot leave a pooled connection unprotected, and used only by migrations, seeders and
four platform commands.

### Authentication

- **Timing equalisation.** An unknown address still pays for a bcrypt comparison, so response
  time does not reveal whether an account exists.
- **Status checked after the password.** Checking first would let an attacker distinguish a
  suspended account from a non-existent one with no credential at all.
- **`TwoFactorRequired` does not count toward lockout.** The password was correct; counting it
  would lock out every user who takes a moment to open their authenticator.
- **A wrong second factor does.** At that point the attacker holds the password, and the second
  factor is the only remaining control.
- **Step-up accepts TOTP only, never a recovery code.** A recovery code is what you use when the
  device is lost; accepting it for step-up would let one intercepted code authorise an ownership
  transfer.
- **Recovery codes are consumed by conditional UPDATE**, so two concurrent requests presenting
  the same code cannot both succeed.

### Account enumeration

Closed at four surfaces: sign-in returns one message for every cause; `forgot-password` always
returns the same 202; a missing model returns 404 without naming it; a permission failure never
names the missing permission. Workspace slugs are inherently public — they appear in the
hostname — so the availability endpoint discloses nothing new, but is rate-limited anyway.

### Credential handling

- Password hashes are never returned; `$hidden` covers `password`, `remember_token`,
  `two_factor_secret`.
- Administrators **send a reset link**, never set a password. No administrator ever knows a
  colleague's credential, so no action taken with it is deniable.
- A token's plaintext is returned exactly once, at creation. A recoverable token is one that
  lives in every log and browser cache that ever saw the list endpoint.
- Token abilities are intersected with the creator's own permissions, so a token can never
  outrank the person who issued it.
- The redaction list is shared between the audit recorder and the log scrubber, so a value that
  never reaches a log line never reaches the audit trail either.

### Audit integrity

Append-only is enforced by a database trigger, not convention, with two announced exceptions.
Sealing may only fill three columns on an unsealed row while every meaningful column stays
byte-identical — so the sealer cannot rewrite history despite holding UPDATE rights. `TRUNCATE`
is blocked separately, because it bypasses row triggers.

The recorder never fails a business operation: a malformed payload lands on the `audit` channel
at `critical` rather than rolling back a valid change. **That fallback must be alarmed on** — a
persistently failing audit write is a compliance incident.

## Residual risks

| Risk | Severity | Mitigation | Owner |
| --- | --- | --- | --- |
| **Nothing has been executed.** 226 backend classes and 35 front-end files are unverified against real Laravel 12, spatie 6, google2fa 8 and Vue 3.5 APIs | **High** | Install the toolchain; run the seven checks in [PHASE-1-STATUS.md](PHASE-1-STATUS.md) | Next session |
| **No test suite.** Every control above is a design claim | **High** | Feature tests, starting with tenant isolation under real RLS | Next session |
| PgBouncer transaction pooling would break the session-scoped RLS variable, silently | **High** | Documented in [aws.md](deployment/aws.md#the-five-things-that-will-bite-you); needs an integration test against the pooler | Before production |
| The `audit_logs` seal trigger is the only PL/pgSQL in the platform and is unproven | Medium | Test that a legitimate seal passes and every other UPDATE is refused | Next session |
| Unsealed audit window (≤5 min) is not yet tamper-evident | Low — accepted | Deliberate trade for throughput; rows are still written atomically. See the migration header | Accepted |
| Pruning breaks the oldest chain links by design | Low | `asids:audit-prune` says so and instructs recording the new chain origin | Accepted |
| No brute-force protection on the step-up code beyond `throttle:two-factor` | Low | Session already authenticated; 10/min is adequate | Accepted |
| Device recognition rests on a signed cookie, not a fingerprint | Low — deliberate | Fingerprints collide across identical corporate laptops, merging several people into one "device" and making revocation meaningless | Accepted |
| Front-end permission checks could be edited in the DOM | None | Presentation only; the server authorises every request | By design |

## Sri Lankan compliance readiness

Phase 1 builds the *structure*, not the rules. `CompliancePackContract` is the seam; VAT, SVAT,
TIN validation, EPF/ETF, PAYE/APIT and RAMIS arrive with the phases that need them, and must be
reviewed by a Sri Lankan chartered accountant before release — not inferred from documentation.

What exists now: seven-year audit retention matching accounting record-keeping expectations;
`tax_identification_number`, `vat_registration_number` and `svat_registration_number` columns
with a database constraint that SVAT implies VAT; an April-default fiscal year matching the
statutory assessment year; and `ap-southeast-1` as the primary region for data-residency answers.

## Sign-off

**Not signed off.** This is a design review of unexecuted code. A security sign-off requires the
test suite, a run of `asids:security-check` against a real deployment, and — for a product
handling third-party financial data — an external penetration test before the first paying
customer.
