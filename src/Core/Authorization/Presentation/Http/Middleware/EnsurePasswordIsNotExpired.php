<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Middleware;

use Asids\Core\Identity\Domain\Exceptions\PasswordExpired;
use Asids\Core\Identity\Domain\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a signed-in user with an expired password from doing anything except changing
 * it.
 *
 * Applied to the authenticated route group rather than at sign-in, deliberately: the
 * user must be able to *reach* the change-password and sign-out endpoints, so they are
 * authenticated but confined. Rejecting them at sign-in would leave them with no way to
 * comply.
 */
final class EnsurePasswordIsNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->passwordHasExpired()) {
            throw new PasswordExpired();
        }

        return $next($request);
    }
}
