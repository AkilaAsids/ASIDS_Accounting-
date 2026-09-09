<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;

/**
 * Who may read and record supplier payments — the payable-side settlement mirror of `BillPolicy`, with the
 * draft/post split collapsed (ADR 0020 §E3).
 *
 * A payment is recorded and posted in one step (Gate-1 dec. 5), so the bill's three-way `{view, draft, post}`
 * becomes `{view, post}`. There is no `update`, `delete`, `post(…, $payment)` or `cancel`: a payment is born
 * posted (no separate post of an existing model), immutable (§A5), and uncancellable this wave.
 *
 * `create()` is the sensitive "record and post" capability. It checks only the permission — there is no model
 * yet — and company membership is validated by `PaymentService` against the target company, exactly as
 * `BillPolicy::create` checks only the permission and the service validates the company. `view()` checks
 * permission AND membership: two different questions, both of which must be true.
 */
final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.payments.view');
    }

    public function view(User $user, SupplierPayment $payment): bool
    {
        return $user->can('purchasing.payments.view')
            && $user->canAccessCompany($payment->company_id);
    }

    /**
     * Recording and posting a payment — the sensitive, money-moving capability.
     */
    public function create(User $user): bool
    {
        return $user->can('purchasing.payments.post');
    }
}
