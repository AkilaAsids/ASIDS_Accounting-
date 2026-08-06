<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Console;

use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifies that the deployment's security assumptions actually hold.
 *
 * Intended to run in the release pipeline immediately after `migrate`, and to fail the deploy
 * if it does not pass. Every check here covers a misconfiguration that is silent — the
 * application boots, serves traffic and looks healthy while a protection is switched off.
 * Row level security is the worst of them: connect as the schema owner without FORCE and the
 * policies simply do not apply, with no error anywhere.
 */
final class SecurityCheckCommand extends Command
{
    protected $signature = 'asids:security-check {--strict : Treat warnings as failures}';

    protected $description = 'Verify row level security, debug flags and credential hygiene';

    /** @var list<array{name: string, status: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkRowLevelSecurity();
        $this->checkDatabaseRole();
        $this->checkDebugSettings();
        $this->checkApplicationKey();
        $this->checkSessionSecurity();
        $this->checkTwoFactorPolicy();

        $this->render();

        $failed = array_filter($this->results, static fn (array $r): bool => $r['status'] === 'fail');
        $warned = array_filter($this->results, static fn (array $r): bool => $r['status'] === 'warn');

        if ($failed !== []) {
            $this->components->error(sprintf('%d check(s) failed.', count($failed)));

            return self::FAILURE;
        }

        if ($warned !== [] && $this->option('strict')) {
            $this->components->error(sprintf('%d warning(s), and --strict was given.', count($warned)));

            return self::FAILURE;
        }

        $this->components->info('All security checks passed.');

        return self::SUCCESS;
    }

    private function checkRowLevelSecurity(): void
    {
        if (! (bool) config('asids.tenancy.enforce_rls')) {
            // A *policies exist but publishing is off* mismatch is far worse than either state
            // alone: the policies constrain every query, nothing publishes a tenant for them to
            // match, and the application reads empty result sets everywhere with no error. It
            // presents as "all my data vanished", which is the least diagnosable outcome
            // available, so it is always a hard failure regardless of environment.
            /** @var object{enabled: bool}|null $relation */
            $relation = DB::selectOne(
                'SELECT relrowsecurity AS enabled FROM pg_class WHERE relname = ?',
                ['companies']
            );

            if ($relation !== null && (bool) $relation->enabled) {
                $this->record(
                    'Row level security',
                    'fail',
                    'TENANCY_ENFORCE_RLS is off but the policies exist in the database. Nothing '
                    .'will publish a tenant for them to match, so every tenant-scoped query '
                    .'returns nothing. Either turn enforcement on, or roll back the row level '
                    .'security migration.',
                );

                return;
            }

            $this->record(
                'Row level security',
                app()->environment('local', 'testing') ? 'warn' : 'fail',
                'TENANCY_ENFORCE_RLS is off. Tenant isolation rests on the Eloquent scope alone.',
            );

            return;
        }

        // Checked against a table that is NOT NULL on tenant_id, so a false positive is not
        // possible through the nullable-tenant policy branch.
        $enforced = RowLevelSecurity::isEnforced('companies');

        $this->record(
            'Row level security',
            $enforced ? 'pass' : 'fail',
            $enforced
                ? 'Policies are in force for the connecting role.'
                : 'Policies exist but do NOT apply to the connecting role. Connect as a NOBYPASSRLS role, or ensure tables are FORCED.',
        );
    }

    private function checkDatabaseRole(): void
    {
        /** @var object{rolsuper: bool, rolbypassrls: bool}|null $role */
        $role = DB::selectOne(
            'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'
        );

        if ($role === null) {
            $this->record('Database role', 'warn', 'Could not inspect the connecting role.');

            return;
        }

        $privileged = (bool) $role->rolsuper || (bool) $role->rolbypassrls;

        $this->record(
            'Database role',
            $privileged ? (app()->environment('local', 'testing') ? 'warn' : 'fail') : 'pass',
            $privileged
                ? 'The application connects as a SUPERUSER or BYPASSRLS role. It must not in production.'
                : 'Connecting role cannot bypass row level security.',
        );
    }

    private function checkDebugSettings(): void
    {
        $debug = (bool) config('app.debug');
        $production = app()->isProduction();

        // The message must match the verdict. Reporting PASS beside "stack traces would be
        // exposed" trains an operator to skim past this check, which is the opposite of useful.
        $this->record(
            'Debug mode',
            match (true) {
                $debug && $production => 'fail',
                $debug => 'warn',
                default => 'pass',
            },
            match (true) {
                $debug && $production => 'APP_DEBUG is on in production. Stack traces and configuration are exposed on error.',
                $debug => 'APP_DEBUG is on, which is expected outside production.',
                default => 'APP_DEBUG is off.',
            },
        );
    }

    private function checkApplicationKey(): void
    {
        $key = (string) config('app.key');

        // The key encrypts the two factor secrets and signs every account link. A default or
        // short key makes both forgeable.
        $valid = $key !== '' && str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32;

        $this->record(
            'Application key',
            $valid ? 'pass' : 'fail',
            $valid
                ? 'A 256-bit key is configured.'
                : 'APP_KEY is missing or not a 256-bit base64 key. Two factor secrets and signed links depend on it.',
        );
    }

    private function checkSessionSecurity(): void
    {
        $issues = [];

        if (! (bool) config('session.encrypt')) {
            $issues[] = 'SESSION_ENCRYPT is off';
        }

        if (app()->isProduction() && ! (bool) config('session.secure')) {
            $issues[] = 'SESSION_SECURE_COOKIE is off';
        }

        if (config('session.same_site') === null) {
            $issues[] = 'SESSION_SAME_SITE is unset';
        }

        $this->record(
            'Session cookies',
            $issues === [] ? 'pass' : (app()->isProduction() ? 'fail' : 'warn'),
            $issues === [] ? 'Encrypted, secure, same-site constrained.' : implode('; ', $issues).'.',
        );
    }

    private function checkTwoFactorPolicy(): void
    {
        $enforced = (bool) config('asids.auth.two_factor.enforced');

        // A warning, never a failure: whether to mandate a second factor is a customer's
        // commercial decision, not a deployment defect.
        $this->record(
            'Two factor policy',
            $enforced ? 'pass' : 'warn',
            $enforced
                ? 'Two factor authentication is mandatory for every user.'
                : 'Two factor authentication is optional. Consider mandating it for workspaces handling payroll or banking.',
        );
    }

    private function record(string $name, string $status, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    }

    private function render(): void
    {
        foreach ($this->results as $result) {
            $label = match ($result['status']) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };

            $this->line(sprintf('  %s  %-22s %s', $label, $result['name'], $result['detail']));
        }

        $this->newLine();
    }
}
