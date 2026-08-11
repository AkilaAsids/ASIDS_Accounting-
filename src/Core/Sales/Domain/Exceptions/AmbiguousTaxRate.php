<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\PlatformException;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * More than one rate covers the same date for the same code.
 *
 * This should be unreachable. The `tax_codes_no_overlapping_rates` exclusion constraint exists precisely
 * to make it so, and nothing in the application can produce it. Reaching here therefore means the
 * constraint was dropped, disabled, or bypassed — by a migration, a manual fix, or a restore from a
 * backup taken before it existed.
 *
 * It is raised rather than resolved because there is no correct way to choose. `first()` would pick
 * whichever row the planner happened to return, so two invoices on the same day could be taxed
 * differently and the books would be internally inconsistent with no error anywhere. An unreachable
 * branch that fails loudly is worth keeping; an unreachable branch that guesses is not.
 *
 * A 500 rather than a 409, deliberately. A conflict tells the caller to retry with different input, and
 * there is no input that fixes this — the data is wrong and an engineer has to look at it.
 */
final class AmbiguousTaxRate extends PlatformException
{
    public static function forCode(string $code, CarbonImmutable $date, int $matches): self
    {
        return new self(
            sprintf(
                'Tax code %s has %d rates covering %s. Exactly one is possible while the overlap constraint '
                .'is in force, so it has been dropped or bypassed. Nothing can be invoiced against this code '
                .'until the duplicate ranges are corrected.',
                $code,
                $matches,
                $date->toDateString(),
            ),
            ['code' => $code, 'date' => $date->toDateString(), 'matches' => $matches],
        );
    }

    public function problemCode(): string
    {
        return 'ambiguous-tax-rate';
    }

    public function problemTitle(): string
    {
        return 'Tax configuration is inconsistent';
    }

    public function problemStatus(): int
    {
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }
}
