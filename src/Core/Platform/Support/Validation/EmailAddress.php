<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Support\Validation;

/**
 * The e-mail validation rule sets, in one place.
 *
 * Two of them, and the distinction is deliberate rather than incidental:
 *
 *  - `syntax()` is for an address the caller already knows — signing in, requesting a password
 *    reset. A DNS lookup there adds a network round trip to the two endpoints that must stay fast
 *    under a credential-stuffing attempt, and it tells the attacker nothing useful either way.
 *
 *  - `deliverable()` is for an address we are about to send something to and cannot otherwise
 *    verify — an invitation, a workspace sign-up. A typo means the message silently never arrives
 *    and whoever sent it has no way to find out.
 *
 * The domain check is configurable so the test suite does not depend on DNS egress; see
 * `asids.validation.verify_email_domain`.
 */
final class EmailAddress
{
    /**
     * @return list<string>
     */
    public static function syntax(): array
    {
        return ['string', 'email:rfc'];
    }

    /**
     * @return list<string>
     */
    public static function deliverable(): array
    {
        return [
            'string',
            config('asids.validation.verify_email_domain') === true ? 'email:rfc,dns' : 'email:rfc',
        ];
    }
}
