<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\Services;

use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Personal access tokens for integrations and mobile clients.
 *
 * The rule that governs this class: **a token can never be more privileged than the user who
 * created it.** Requested abilities are intersected with the creator's own permissions, so a
 * bookkeeper cannot mint a token that approves invoices — and a token whose owner is later
 * demoted loses the corresponding abilities at check time, because authorisation consults the
 * user as well as the token.
 */
final readonly class AccessTokenService
{
    /**
     * @param  list<string>  $requestedAbilities
     * @param  list<string>  $allowedIpRanges
     * @return array{token: PersonalAccessToken, plaintext: string}
     */
    public function issue(
        User $owner,
        string $name,
        array $requestedAbilities,
        ?string $description = null,
        ?int $expiresInDays = null,
        array $allowedIpRanges = [],
        ?string $createdIp = null,
    ): array {
        $this->assertWithinTokenLimit($owner);

        $abilities = $this->intersectWithOwnerPermissions($owner, $requestedAbilities);

        if ($abilities === []) {
            throw BusinessRuleViolation::make(
                code: 'no-grantable-abilities',
                message: 'None of the requested abilities are ones your account holds, so no token was created.',
            );
        }

        $expiresAt = now()->addDays(
            $expiresInDays ?? (int) config('asids.auth.tokens.default_expiry_days', 365)
        );

        return DB::transaction(function () use ($owner, $name, $abilities, $description, $expiresAt, $allowedIpRanges, $createdIp): array {
            /** @var NewAccessToken $issued */
            $issued = $owner->createToken($name, $abilities, $expiresAt);

            /** @var PersonalAccessToken $token */
            $token = $issued->accessToken;

            // Sanctum's `createToken` writes only its own columns, so the ASIDS audit columns
            // are filled immediately afterwards inside the same transaction.
            $token->forceFill([
                'tenant_id' => $owner->tenant_id,
                'description' => $description,
                'created_by_id' => $owner->getKey(),
                'created_ip' => $createdIp,
                'allowed_ip_ranges' => $allowedIpRanges === [] ? null : array_values($allowedIpRanges),
            ])->save();

            return [
                'token' => $token,
                // Returned once and never stored in plaintext.
                'plaintext' => $issued->plainTextToken,
            ];
        });
    }

    public function revoke(PersonalAccessToken $token, User $revokedBy, string $reason = 'revoked_by_user'): PersonalAccessToken
    {
        if ($token->revoked_at !== null) {
            return $token;
        }

        // Revoked, not deleted: "when was this integration turned off, and by whom" must stay
        // answerable after the fact.
        $token->forceFill([
            'revoked_at' => now(),
            'revoked_by_id' => $revokedBy->getKey(),
            'revocation_reason' => $reason,
        ])->save();

        return $token;
    }

    /**
     * Sweeps tokens whose expiry has passed, marking them revoked so the reason is recorded
     * rather than inferred. Run nightly by the scheduler.
     */
    public function revokeExpired(): int
    {
        return PersonalAccessToken::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => 'expired',
            ]);
    }

    /**
     * A token may hold only abilities its creator actually has.
     *
     * Silently dropping the rest rather than failing would issue a token that appears to grant
     * more than it does, so the caller is told when nothing survives the intersection.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function intersectWithOwnerPermissions(User $owner, array $requested): array
    {
        // An owner holds every capability implicitly via the gate, so there is nothing to
        // intersect against — the request is honoured as written.
        if ($owner->isTenantOwner()) {
            return array_values(array_unique($requested));
        }

        $held = $owner->permissionNames();

        return array_values(array_intersect(array_unique($requested), $held));
    }

    private function assertWithinTokenLimit(User $owner): void
    {
        $limit = (int) config('asids.auth.tokens.max_per_user', 25);

        $live = PersonalAccessToken::query()
            ->where('tokenable_id', $owner->getKey())
            ->whereNull('revoked_at')
            ->count();

        if ($live >= $limit) {
            throw BusinessRuleViolation::make(
                code: 'token-limit-reached',
                message: sprintf('You already have %d active API tokens. Revoke one before creating another.', $limit),
                context: ['token_limit' => $limit],
            );
        }
    }
}
