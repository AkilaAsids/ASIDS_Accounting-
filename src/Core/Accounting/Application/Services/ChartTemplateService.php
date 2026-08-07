<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Application\DTOs\CreateAccountData;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Applies the starter chart, and guarantees the system accounts exist either way.
 *
 * Two operations that look similar and are not:
 *
 *   * `apply()` builds a whole starter chart, and only onto a company that has none. Applying it
 *     over an existing chart would merge two numbering schemes into one unusable list.
 *   * `ensureSystemAccounts()` creates only retained earnings and opening balance equity, and is
 *     safe to call on any company at any time. A company that declined the template still needs
 *     somewhere for the year-end close to put net income.
 */
final readonly class ChartTemplateService
{
    public function __construct(private ChartOfAccountsService $accounts) {}

    /**
     * Build the starter chart for a company that has none.
     *
     * @return int The number of accounts created.
     */
    public function apply(Company $company): int
    {
        if (Account::query()->forCompany($company->getKey())->exists()) {
            // Refused rather than merged. Two numbering schemes interleaved is worse than either,
            // and the customer cannot tell afterwards which accounts came from where.
            throw BusinessRuleViolation::make(
                code: 'chart-already-exists',
                message: 'This company already has accounts. The starter chart can only be applied to an empty chart.',
            );
        }

        return DB::transaction(function () use ($company): int {
            $created = [];

            foreach (ChartTemplate::accounts() as $definition) {
                $account = $this->accounts->create($company, new CreateAccountData(
                    code: $definition['code'],
                    name: $definition['name'],
                    type: $definition['type'],
                    // Resolved from what has already been created in this pass. The template lists
                    // parents before children, so the lookup always succeeds.
                    parentId: $definition['parent'] === null ? null : ($created[$definition['parent']] ?? null),
                    isPostable: $definition['postable'],
                    sortOrder: (int) $definition['code'],
                    systemKey: $definition['system'],
                    templateVersion: ChartTemplate::VERSION,
                ));

                $created[$definition['code']] = $account->getKey();
            }

            return count($created);
        });
    }

    /**
     * Create any system account the company is missing, and nothing else.
     *
     * Idempotent: called after applying the template, after creating a company with an empty chart,
     * and safe to call again. The uniqueness of a system key per company is enforced by a partial
     * index, so a race produces a conflict rather than a duplicate.
     *
     * @return list<Account>
     */
    public function ensureSystemAccounts(Company $company): array
    {
        $existing = Account::query()
            ->forCompany($company->getKey())
            ->whereNotNull('system_key')
            ->pluck('system_key')
            ->all();

        $created = [];

        foreach (ChartTemplate::requiredSystemAccounts() as $definition) {
            if (in_array($definition['system'], $existing, true)) {
                continue;
            }

            $created[] = $this->accounts->create($company, new CreateAccountData(
                code: $this->availableCode($company, $definition['code']),
                name: $definition['name'],
                type: $definition['type'],
                isPostable: $definition['postable'],
                sortOrder: (int) $definition['code'],
                systemKey: $definition['system'],
            ));
        }

        return $created;
    }

    /**
     * The template's preferred code, or the first free variation of it.
     *
     * A company with its own chart may already be using 3200 for something else. The system account
     * still has to exist, and its code is not what the platform resolves it by — so taking the next
     * free number is better than refusing to create it.
     */
    private function availableCode(Company $company, string $preferred): string
    {
        $taken = Account::query()
            ->forCompany($company->getKey())
            ->whereRaw('lower(code) = ?', [strtolower($preferred)])
            ->exists();

        if (! $taken) {
            return $preferred;
        }

        for ($suffix = 1; $suffix < 100; $suffix++) {
            $candidate = $preferred.'-'.$suffix;

            $exists = Account::query()
                ->forCompany($company->getKey())
                ->whereRaw('lower(code) = ?', [strtolower($candidate)])
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw BusinessRuleViolation::make(
            code: 'no-available-account-code',
            message: sprintf('Could not find a free account code near “%s”.', $preferred),
        );
    }
}
