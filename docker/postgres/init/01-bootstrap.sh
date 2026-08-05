#!/usr/bin/env bash
#
# One-time PostgreSQL bootstrap for ASIDS ERP Cloud.
#
# Runs as the cluster superuser the first time the data volume is created.
# It establishes the *two-role* model the platform depends on:
#
#   asids_owner (superuser)  — owns the schema, runs migrations that must create
#                              extensions, roles or row level security policies.
#   asids_app   (NOBYPASSRLS) — the only role the application connects as. Because
#                              it cannot bypass row level security, a missing
#                              `tenant_id` filter fails closed at the database
#                              instead of leaking another tenant's ledger.
#
# Re-running is harmless: every statement is idempotent.

set -Eeuo pipefail

readonly APP_USER="${APP_DB_USER:-asids_app}"
readonly APP_PASSWORD="${APP_DB_PASSWORD:-secret}"
readonly MAIN_DB="${POSTGRES_DB:-asids_erp}"
readonly TEST_DB="${TEST_DB_NAME:-${MAIN_DB}_testing}"

psql_super() {
    psql --username "${POSTGRES_USER}" --dbname "${1}" \
         --no-password --set ON_ERROR_STOP=1 --quiet
}

echo "[postgres-init] creating application role '${APP_USER}'"
psql_super "${MAIN_DB}" <<-SQL
    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${APP_USER}') THEN
            CREATE ROLE ${APP_USER} LOGIN PASSWORD '${APP_PASSWORD}';
        ELSE
            ALTER ROLE ${APP_USER} LOGIN PASSWORD '${APP_PASSWORD}';
        END IF;
    END
    \$\$;

    -- Explicitly deny the two privileges that would defeat tenant isolation.
    ALTER ROLE ${APP_USER} NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB;

    -- Sensible per-role defaults. statement_timeout protects the pool from a
    -- runaway report; the ERP's own long jobs raise it explicitly per query.
    ALTER ROLE ${APP_USER} SET statement_timeout = '30s';
    ALTER ROLE ${APP_USER} SET idle_in_transaction_session_timeout = '60s';
    ALTER ROLE ${APP_USER} SET lock_timeout = '10s';
    ALTER ROLE ${APP_USER} SET timezone = 'UTC';
    ALTER ROLE ${APP_USER} SET search_path = public;
SQL

echo "[postgres-init] creating test database '${TEST_DB}'"
if ! psql --username "${POSTGRES_USER}" --dbname postgres --no-password -tAc \
        "SELECT 1 FROM pg_database WHERE datname = '${TEST_DB}'" | grep -q 1; then
    createdb --username "${POSTGRES_USER}" --owner "${POSTGRES_USER}" "${TEST_DB}"
fi

for db in "${MAIN_DB}" "${TEST_DB}"; do
    echo "[postgres-init] provisioning extensions and grants on '${db}'"
    psql_super "${db}" <<-SQL
        -- Case-insensitive text for e-mail addresses and codes; trigram and
        -- unaccent indexes back the "search as you type" pickers; pgcrypto
        -- provides gen_random_uuid() and digest() for the audit hash chain.
        CREATE EXTENSION IF NOT EXISTS citext;
        CREATE EXTENSION IF NOT EXISTS pgcrypto;
        CREATE EXTENSION IF NOT EXISTS pg_trgm;
        CREATE EXTENSION IF NOT EXISTS unaccent;
        CREATE EXTENSION IF NOT EXISTS btree_gin;
        CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

        GRANT CONNECT ON DATABASE ${db} TO ${APP_USER};
        GRANT USAGE, CREATE ON SCHEMA public TO ${APP_USER};

        -- Existing and future objects created by the owner must be reachable by
        -- the application role. Migrations run as the owner.
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO ${APP_USER};
        GRANT USAGE, SELECT                 ON ALL SEQUENCES  IN SCHEMA public TO ${APP_USER};
        GRANT EXECUTE                       ON ALL FUNCTIONS  IN SCHEMA public TO ${APP_USER};

        ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${APP_USER};
        ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
            GRANT USAGE, SELECT ON SEQUENCES TO ${APP_USER};
        ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
            GRANT EXECUTE ON FUNCTIONS TO ${APP_USER};

        -- The application role owns the objects it creates itself (Laravel runs
        -- migrations as DB_USERNAME in local development), so grant the same
        -- defaults for objects it creates.
        ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_USER} IN SCHEMA public
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${APP_USER};
SQL
done

echo "[postgres-init] done"
