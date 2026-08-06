<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Application\Services;

use Asids\Core\Organization\Domain\Contracts\LedgerActivityProbe;
use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Organization\Domain\Exceptions\BranchLimitReached;
use Asids\Core\Organization\Domain\Exceptions\CannotArchive;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Illuminate\Support\Facades\DB;

/**
 * Branch lifecycle.
 *
 * The invariant that shapes this class: every company has exactly one active primary
 * branch, always. It is where transactions land when a document does not name a branch, so
 * a company without one cannot post at all.
 */
final readonly class BranchService
{
    public function __construct(private LedgerActivityProbe $ledger) {}

    /**
     * Create the primary branch that a company cannot exist without.
     *
     * Called only from CompanyService, inside the company-creation transaction.
     */
    public function createPrimary(Company $company, string $name, string $code): Branch
    {
        $branch = new Branch();

        $branch->fill([
            'company_id' => $company->getKey(),
            'name' => $name,
            'code' => $this->uniqueCode($company, $code),
            // Inherited rather than duplicated: a head office shares its company's locale
            // until someone deliberately overrides it.
            'country_code' => $company->country_code,
            'timezone' => $company->timezone,
            'email' => $company->email,
            'phone' => $company->phone,
            'address_line_1' => $company->address_line_1,
            'address_line_2' => $company->address_line_2,
            'city' => $company->city,
            'district' => $company->district,
            'postal_code' => $company->postal_code,
        ]);

        $branch->is_primary = true;
        $branch->status = OrganizationStatus::Active;
        $branch->save();

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Company $company, array $attributes): Branch
    {
        if (! $company->isActive()) {
            throw BusinessRuleViolation::make(
                code: 'archived-company-cannot-gain-branches',
                message: 'A branch cannot be added to an archived company.',
            );
        }

        $this->assertWithinBranchLimit($company);

        $code = isset($attributes['code']) && is_string($attributes['code']) && trim($attributes['code']) !== ''
            ? strtoupper(trim($attributes['code']))
            : $this->derivedCode((string) ($attributes['name'] ?? ''));

        if ($this->codeExists($company, $code)) {
            throw ResourceConflict::duplicate('branch', 'code', $code);
        }

        $branch = new Branch();

        $branch->fill([
            ...$attributes,
            'company_id' => $company->getKey(),
            'code' => $code,
        ]);

        $branch->is_primary = false;
        $branch->status = OrganizationStatus::Active;
        $branch->save();

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Branch $branch, array $attributes): Branch
    {
        // The code appears on posted document numbers, so it is fixed once anything has
        // been recorded against the branch — otherwise historical documents would refer to
        // a code that no longer resolves.
        if (isset($attributes['code'])) {
            $incoming = strtoupper(trim((string) $attributes['code']));

            if ($incoming !== $branch->code) {
                if ($this->ledger->branchHasActivity($branch)) {
                    throw BusinessRuleViolation::make(
                        code: 'branch-code-locked',
                        message: 'A branch code cannot be changed once transactions have been recorded against it.',
                    );
                }

                if ($this->codeExists($branch->company, $incoming, excluding: $branch->getKey())) {
                    throw ResourceConflict::duplicate('branch', 'code', $incoming);
                }
            }
        }

        $branch->fill($attributes);
        $branch->save();

        return $branch;
    }

    /**
     * Move the primary designation to another branch of the same company.
     *
     * The two writes are inseparable: the partial unique index permits only one primary per
     * company, so writing the new one before clearing the old would be rejected — and
     * clearing the old first would briefly leave the company with nowhere to post.
     */
    public function makePrimary(Branch $branch): Branch
    {
        if (! $branch->isActive()) {
            throw BusinessRuleViolation::make(
                code: 'archived-branch-cannot-be-primary',
                message: 'An archived branch cannot be made primary.',
            );
        }

        if ($branch->is_primary) {
            return $branch;
        }

        return DB::transaction(function () use ($branch): Branch {
            Branch::query()
                ->forCompany($branch->company_id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $branch->is_primary = true;
            $branch->save();

            return $branch;
        });
    }

    public function archive(Branch $branch): Branch
    {
        if ($branch->is_primary) {
            throw CannotArchive::primaryBranch($branch->name);
        }

        $branch->status = OrganizationStatus::Archived;
        $branch->archived_at = now();
        $branch->save();

        return $branch;
    }

    public function restore(Branch $branch): Branch
    {
        if ($branch->isActive()) {
            return $branch;
        }

        $this->assertWithinBranchLimit($branch->company);

        $branch->status = OrganizationStatus::Active;
        $branch->archived_at = null;
        $branch->save();

        return $branch;
    }

    private function assertWithinBranchLimit(Company $company): void
    {
        $limit = (int) config('asids.limits.max_branches_per_company');

        $active = Branch::query()
            ->forCompany($company->getKey())
            ->active()
            ->count();

        if ($active >= $limit) {
            throw BranchLimitReached::at($limit);
        }
    }

    private function derivedCode(string $name): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return $code === '' ? 'BR' : mb_substr($code, 0, 12);
    }

    private function uniqueCode(Company $company, string $base): string
    {
        $base = mb_substr(strtoupper($base), 0, 24);

        if (! $this->codeExists($company, $base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 999; $suffix++) {
            $suffixString = (string) $suffix;
            $candidate = mb_substr($base, 0, 24 - mb_strlen($suffixString) - 1).'-'.$suffixString;

            if (! $this->codeExists($company, $candidate)) {
                return $candidate;
            }
        }

        throw BusinessRuleViolation::make(
            code: 'branch-code-exhausted',
            message: 'Could not generate a unique branch code. Please supply one explicitly.',
        );
    }

    private function codeExists(Company $company, string $code, ?string $excluding = null): bool
    {
        return Branch::query()
            ->withTrashed()
            ->forCompany($company->getKey())
            ->whereRaw('upper(code) = ?', [strtoupper($code)])
            ->when($excluding !== null, static fn ($query) => $query->whereKeyNot($excluding))
            ->exists();
    }
}
