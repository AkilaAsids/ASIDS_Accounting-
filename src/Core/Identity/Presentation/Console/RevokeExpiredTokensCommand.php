<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Console;

use Asids\Core\Identity\Application\Services\AccessTokenService;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Console\Command;

/**
 * Marks tokens whose expiry has passed as revoked.
 *
 * Sanctum already refuses an expired token at authentication time, so this is not a security
 * control — it is a bookkeeping one. Without it the tokens list shows "active" rows that are
 * not, and nobody can distinguish an integration that was deliberately switched off from one
 * that quietly aged out.
 */
final class RevokeExpiredTokensCommand extends Command
{
    protected $signature = 'asids:revoke-expired-tokens';

    protected $description = 'Mark personal access tokens whose expiry has passed as revoked';

    public function handle(AccessTokenService $tokens): int
    {
        // Crosses every workspace by design, so the policies are suspended for the sweep and
        // only for the sweep.
        $revoked = RowLevelSecurity::bypass(static fn (): int => $tokens->revokeExpired());

        $this->components->info(sprintf('%d expired token(s) marked revoked.', $revoked));

        return self::SUCCESS;
    }
}
