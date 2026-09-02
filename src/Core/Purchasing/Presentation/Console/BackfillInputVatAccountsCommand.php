<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Presentation\Console;

use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Points VAT-charging tax codes with no input account at their company's `1170 Input VAT Recoverable` leaf.
 *
 * The input-VAT posting refusal (`BillCannotBePosted::taxCodeHasNoInputAccount`, AC-3.7) is the guarantee: a
 * bill whose tax code has no `input_account_id` cannot post. This command is the reviewable one-step remedy for
 * the day-one state where most tenants' VAT codes have that column null, because `input_account_id` was
 * "reserved for purchasing" and populated by nobody. It is deliberately conservative:
 *
 *   * **Dry-run by default.** It reports and changes nothing without an explicit `--apply`.
 *   * **It never guesses.** It fills a code only when the company has exactly one active, postable
 *     `1170 Input VAT Recoverable` account — else it reports and skips, naming the company. `input_account_id`
 *     is a *setting*, not a system key, so there is no key to resolve it by; ambiguity is left to a human.
 *   * **It never overwrites.** A code that already names an input account is left exactly as configured.
 *   * **It leaves non-charging codes alone.** A zero-rated or exempt code recovers no input tax, so it has
 *     nothing to point anywhere.
 *   * **Idempotent.** A second run finds nothing eligible and changes nothing.
 *
 * It sweeps every company under `RowLevelSecurity::bypass()` — a migration-style platform-wide data touch, not a
 * tenant-scoped one — and asserts the bypass actually took, because a NOBYPASSRLS role on a FORCED table sees
 * zero rows and would report success having done nothing. Raw `DB` queries are used throughout for the same
 * reason the trade-receivables backfill migration does: Eloquent's tenant global scope would restrict the sweep
 * to whichever tenant happened to be published.
 */
final class BackfillInputVatAccountsCommand extends Command
{
    protected $signature = 'purchasing:backfill-input-vat-accounts
                            {--apply : Write the changes. Without this the command is a dry run.}';

    protected $description = 'Point VAT-charging tax codes with no input account at their company’s 1170 Input VAT Recoverable';

    private const string INPUT_VAT_CODE = '1170';

    public function handle(): int
    {
        $apply = $this->option('apply') === true;

        return RowLevelSecurity::bypass(function () use ($apply): int {
            $this->assertBypassEffective();

            // Every company with at least one charging code (rate > 0) that has no input account yet.
            /** @var array<string, string> $companies  id => name */
            $companies = DB::table('tax_codes')
                ->join('companies', 'companies.id', '=', 'tax_codes.company_id')
                ->whereNull('tax_codes.input_account_id')
                ->where('tax_codes.rate', '>', 0)
                ->whereNull('tax_codes.deleted_at')
                ->distinct()
                ->pluck('companies.name', 'companies.id')
                ->all();

            $filled = 0;
            $skipped = 0;

            foreach ($companies as $companyId => $companyName) {
                $candidates = DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', self::INPUT_VAT_CODE)
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('is_postable', true)
                    ->pluck('id');

                if ($candidates->count() !== 1) {
                    // Ambiguous or absent: the command refuses to guess which account is the input-VAT one.
                    $skipped++;
                    $this->components->twoColumnDetail(
                        (string) $companyName,
                        sprintf(
                            '<fg=yellow>skipped — %d Input VAT (1170) account(s); need exactly one</>',
                            $candidates->count(),
                        ),
                    );

                    continue;
                }

                $accountId = (string) $candidates->first();

                $eligible = DB::table('tax_codes')
                    ->where('company_id', $companyId)
                    ->whereNull('input_account_id')
                    ->where('rate', '>', 0)
                    ->whereNull('deleted_at');

                $count = (clone $eligible)->count();

                if ($apply) {
                    (clone $eligible)->update([
                        'input_account_id' => $accountId,
                        'updated_at' => now(),
                    ]);
                    $this->components->twoColumnDetail(
                        (string) $companyName,
                        sprintf('<fg=green>%d code(s) pointed at 1170</>', $count),
                    );
                } else {
                    $this->components->twoColumnDetail(
                        (string) $companyName,
                        sprintf('%d code(s) would be pointed at 1170', $count),
                    );
                }

                $filled += $count;
            }

            $this->components->info($apply
                ? sprintf('Filled %d tax code(s); skipped %d company(ies).', $filled, $skipped)
                : sprintf(
                    'Dry run: %d tax code(s) would be filled; %d company(ies) would be skipped. Re-run with '
                    .'--apply to write.',
                    $filled,
                    $skipped,
                ));

            return self::SUCCESS;
        });
    }

    /**
     * Refuse to proceed unless the bypass this command depends on is actually in force.
     *
     * Mirrors the guard the trade-receivables backfill migration learned the hard way: `asids_app` is
     * NOBYPASSRLS and the tables are FORCED, so with the bypass not effective the sweep matches zero rows and
     * PostgreSQL reports success. The cast is in SQL because PDO has returned booleans as the string `'f'`, and
     * `(bool) 'f'` is `true` — the check would pass in exactly the case it exists to catch.
     */
    private function assertBypassEffective(): void
    {
        if ((int) DB::scalar('SELECT asids_rls_bypassed()::int') !== 1) {
            throw new RuntimeException(
                'Row level security is still in force. This command would silently touch nothing, because a '
                .'NOBYPASSRLS role sees no rows on a FORCED table with no tenant published.'
            );
        }
    }
}
