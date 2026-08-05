<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Http\Controllers;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;

/**
 * Base controller for every API endpoint.
 *
 * Keeps two things and nothing else: the authorisation trait, and a typed
 * accessor for the authenticated user. Business logic belongs in a service;
 * query building belongs in a repository; presentation belongs in a resource.
 * Controllers in this codebase should read as a short list of delegations.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    /**
     * The authenticated user.
     *
     * Every route reaching a controller passes through `auth:sanctum`, so a null
     * user here is a routing misconfiguration rather than a runtime possibility —
     * hence the assertion instead of a nullable return that every caller would
     * have to defend against.
     */
    protected function currentUser(): User
    {
        $user = auth()->user();

        assert($user instanceof User, 'A protected endpoint was reached without authentication.');

        return $user;
    }
}
