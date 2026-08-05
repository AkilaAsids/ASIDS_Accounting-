#!/usr/bin/env bash
#
# ASIDS ERP Cloud container entrypoint.
#
# Responsibilities, in order:
#   1. Fail fast on missing configuration rather than booting a broken node.
#   2. Wait for PostgreSQL and Redis so orchestrators do not thrash on restart.
#   3. Harden OPcache when running in production.
#   4. Hand over to the container command (php-fpm, horizon, scheduler, ...).
#
# Database migrations are NOT run here. Running them per-container races between
# replicas; they are executed once per release by a dedicated migration job
# (see docs/deployment/aws.md).

set -Eeuo pipefail

log() { printf '[entrypoint] %s\n' "$*" >&2; }
fail() { log "FATAL: $*"; exit 1; }

readonly APP_ENV="${APP_ENV:-production}"

# ── 1. Configuration sanity ────────────────────────────────────────────────
if [[ ! -f /var/www/html/vendor/autoload.php ]]; then
    if [[ "${APP_ENV}" == "local" || "${APP_ENV}" == "testing" ]]; then
        log 'vendor/ missing — installing Composer dependencies (development only)'
        composer install --no-interaction --prefer-dist
    else
        fail 'vendor/autoload.php is missing; the image was built incorrectly.'
    fi
fi

if [[ "${APP_ENV}" != "local" && "${APP_ENV}" != "testing" ]]; then
    [[ -n "${APP_KEY:-}" ]] || fail 'APP_KEY is not set.'
    [[ "${APP_DEBUG:-false}" == "false" ]] || fail 'APP_DEBUG must be false outside local.'
fi

# ── 2. Dependency readiness ────────────────────────────────────────────────
wait_for() {
    local name="$1" host="$2" port="$3" attempts="${4:-60}"
    local i=0
    until php -r "exit(@fsockopen(\$argv[1], (int) \$argv[2]) ? 0 : 1);" "${host}" "${port}"; do
        i=$((i + 1))
        [[ "${i}" -lt "${attempts}" ]] || fail "${name} at ${host}:${port} never became reachable."
        log "waiting for ${name} at ${host}:${port} (${i}/${attempts})"
        sleep 2
    done
    log "${name} is reachable"
}

wait_for PostgreSQL "${DB_HOST:-postgres}" "${DB_PORT:-5432}"
wait_for Redis "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}"

# ── 3. Runtime hardening / warm-up ─────────────────────────────────────────
if [[ "${APP_ENV}" == "production" || "${APP_ENV}" == "staging" ]]; then
    # The source tree is immutable in a released image, so timestamp checks are
    # pure overhead and preloading is safe.
    {
        echo 'opcache.validate_timestamps=0'
        echo 'opcache.preload=/var/www/html/vendor/autoload.php'
        echo 'opcache.preload_user=asids'
    } >> "${PHP_INI_DIR}/conf.d/zz-opcache.ini"

    php artisan config:cache --no-interaction
else
    php artisan config:clear --no-interaction || true
fi

php artisan storage:link --no-interaction 2>/dev/null || true

log "starting: $*"
exec "$@"
