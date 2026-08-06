<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CompanyArchived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly User $archivedBy,
    ) {}
}
