# AWS deployment

Reference topology for staging and production. Written to be read by someone who has to operate
this at 3am, so it leads with the things that cause outages.

## Topology

```
Route 53  *.erp.asidstech.com
    │
CloudFront ─── S3 (public/build — content-hashed, immutable)
    │
   ALB (TLS 1.3, ACM wildcard cert)
    │
ECS Fargate
 ├── web        nginx + php-fpm      2–20 tasks, target-tracking on ALB RPS
 ├── horizon    queue workers        2–10 tasks, tracking on queue depth
 └── scheduler  schedule:work        exactly 1 task
    │
    ├── RDS PostgreSQL 17  Multi-AZ, one read replica
    ├── ElastiCache Redis  cluster mode off, Multi-AZ, AOF on
    ├── S3                 documents (SSE-KMS), backups (Object Lock)
    └── Secrets Manager    APP_KEY, DB credentials, Meilisearch key
```

## The five things that will bite you

**1. Migrations must run exactly once per release, not per task.**
`docker/php/entrypoint.sh` deliberately does *not* migrate. Ten tasks starting together would
race, and a partially applied migration under load is the worst state this system can be in.
Run a one-off ECS task before shifting traffic:

```bash
aws ecs run-task --cluster asids-prod --task-definition asids-migrate \
  --overrides '{"containerOverrides":[{"name":"app","command":["php","artisan","migrate","--force"]}]}'
```

**2. The scheduler must be exactly one task.** Two would double-execute the audit sealer and
the retention prune. Every scheduled command also carries `onOneServer()`, so Redis locking is
the second line of defence — but do not rely on it as the first.

**3. The application must not connect as the RDS master user.** Row level security does not
apply to a `BYPASSRLS` role, and *nothing errors* — the app boots, serves traffic, looks
healthy, and tenant isolation is off. Create the app role once:

```sql
CREATE ROLE asids_app LOGIN PASSWORD '...' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
GRANT CONNECT ON DATABASE asids_erp TO asids_app;
GRANT USAGE ON SCHEMA public TO asids_app;
ALTER DEFAULT PRIVILEGES FOR ROLE asids_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO asids_app;
```

`asids:security-check` asserts this, and the deploy pipeline must fail on it.

**4. PgBouncer must be in transaction mode, and `SET` breaks under it.**
`RowLevelSecurityBootstrapper` uses `set_config(..., false)` — *session* scope — which
transaction pooling does not preserve across statements. Either run session pooling, or switch
the bootstrapper to `set_config(..., true)` and wrap requests in a transaction. Getting this
wrong disables tenant isolation silently. Verify with an integration test against the pooler,
not against RDS directly.

**5. `TRUSTED_PROXIES` must name the ALB.** Left empty, every audit entry records the load
balancer's IP instead of the client's, and rate limiting buckets the whole internet together.

## Environment

Secrets come from Secrets Manager via task-definition `secrets`, never from `environment`.

```
APP_ENV=production
APP_DEBUG=false                 # asids:security-check fails the deploy otherwise
APP_URL=https://erp.asidstech.com
TENANCY_CENTRAL_DOMAIN=erp.asidstech.com
TENANCY_ENFORCE_RLS=true
TRUSTED_PROXIES=10.0.0.0/16

DB_HOST=asids-prod.<id>.ap-southeast-1.rds.amazonaws.com
DB_USERNAME=asids_app           # never the master user
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.erp.asidstech.com
CACHE_STORE=redis
QUEUE_CONNECTION=redis
FILESYSTEM_DISK=s3
AWS_KMS_KEY_ID=arn:aws:kms:...
AUTH_TWO_FACTOR_ENFORCED=true   # recommended for production workspaces
```

`ap-southeast-1` (Singapore) is the primary region: lowest latency to Colombo of the options
with full service coverage, and an acceptable answer to data-residency questions from Sri
Lankan customers.

## Release sequence

```
1  Build and push image (docker/php/Dockerfile, target=production)
2  Upload public/build to S3; invalidate CloudFront
3  One-off task:  php artisan migrate --force
4  One-off task:  php artisan asids:sync-permissions --refresh-roles
5  One-off task:  php artisan asids:security-check          ← abort on non-zero
6  Update web service (rolling, minHealthy 100%, maxPercent 200%)
7  Update horizon service — SIGTERM, 60s grace, workers finish in flight
8  Update scheduler service
9  Smoke: GET /up, sign in to a canary workspace, POST /api/v1/audit/verify
```

Steps 3–5 run **before** any traffic shifts. Step 5 is the gate that catches the
misconfiguration nothing else reports.

## Backup and recovery

| What | Mechanism | Retention | RPO |
| --- | --- | --- | --- |
| Database | RDS automated + PITR | 35 days | 5 min |
| Database | Manual snapshot pre-release | 90 days | — |
| Documents | S3 versioning + cross-region replication | Indefinite | minutes |
| Audit trail | Included in the database backup | 7 years | 5 min |

**Restore drill, quarterly.** Restore to a scratch cluster, then:

```bash
php artisan asids:audit-verify
```

A restored database whose hash chain does not verify means the backup captured a torn write.
This is the only check that detects it, and an untested backup is not a backup.

## Monitoring

Alarm on these, in this order of severity:

| Severity | Signal |
| --- | --- |
| **Page** | `asids:audit-verify` non-zero — history has been rewritten |
| **Page** | 5xx rate > 1% over 5 min; RDS CPU > 80% for 10 min; Horizon `critical` wait > 60s |
| **Ticket** | `failed_jobs` growth on the `audit` queue — entries not reaching the trail |
| **Ticket** | Log lines from the `audit` channel at `critical` — the recorder's fallback fired |
| **Ticket** | Unsealed audit entries older than 30 min — the sealer is not running |
| **Watch** | `login_histories` failures spiking from one IP, or across many accounts |

The last one distinguishes a single compromised password from credential stuffing, which is
why `login_histories` records failed attempts against addresses that do not exist.

## Scaling notes

- `pm.max_children = 40` per task against `max_connections = 300` means roughly 7 web tasks
  saturate the connection limit. Add PgBouncer before scaling past that — and read point 4.
- Reporting reads should move to the read replica when they appear; the connection is already
  separable in `config/database.php`.
- Partition a tenant-scoped table on `tenant_id` hash only when it passes ~500M rows.
  Premature partitioning complicates every query plan.
