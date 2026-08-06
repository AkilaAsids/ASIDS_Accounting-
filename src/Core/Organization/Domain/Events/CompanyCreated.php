<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A company exists and is ready to keep books.
 *
 * Dispatched after the creation transaction commits. The Accounting phase listens to this
 * to install a default chart of accounts, which is why it must not fire for a company that
 * was rolled back.
 */
final class CompanyCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly User $createdBy,
    ) {}
}
