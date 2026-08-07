<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues gapless document numbers.
 *
 * MUST BE CALLED INSIDE THE DOCUMENT'S OWN TRANSACTION
 * ----------------------------------------------------
 * That is the entire mechanism. The counter row is locked with `SELECT … FOR UPDATE` and incremented
 * in the caller's transaction, so if the document rolls back the number goes back with it. Called
 * outside a transaction, or in one of its own, it would produce gaps exactly like the PostgreSQL
 * sequence it exists to avoid — and the failure would be silent, visible only as a missing number
 * months later.
 *
 * `assertInTransaction()` makes that a loud failure instead of a quiet one.
 *
 * The lock serialises issuance per company, document family and period. Stated plainly because it is
 * a real cost: two people posting a journal voucher for the same company in the same month queue
 * behind each other. For an SME that is nothing; for a high-volume tenant the answer is a
 * non-gapless sequence for internal document types, which is why `DocumentType` already answers
 * `requiresGaplessNumbering()` per case.
 */
final readonly class DocumentNumberService
{
    /**
     * The next number in the series, reserved for this transaction.
     *
     * Format: `JV-2026-04-0001` — family, then the period, then the ordinal. Readable enough that an
     * accountant can tell when a document was issued without opening it.
     */
    public function next(Company $company, DocumentType $type, FiscalPeriod $period): string
    {
        $this->assertInTransaction($type);

        $periodKey = $period->starts_on->format('Y-m');

        // Created if absent, then locked. `insertOrIgnore` rather than a check-then-insert: two
        // concurrent first-uses of a period would otherwise both see no row and both insert, and one
        // would fail on the unique index having already done half its work.
        DB::table('document_sequences')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->getKey(),
            'document_type' => $type->value,
            'period_key' => $periodKey,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{id: string, next_number: int}|null $sequence */
        $sequence = DB::table('document_sequences')
            ->where('company_id', $company->getKey())
            ->where('document_type', $type->value)
            ->where('period_key', $periodKey)
            ->lockForUpdate()
            ->first(['id', 'next_number']);

        if ($sequence === null) {
            throw BusinessRuleViolation::make(
                code: 'document-sequence-missing',
                message: 'The document number sequence could not be read.',
            );
        }

        $number = (int) $sequence->next_number;

        DB::table('document_sequences')
            ->where('id', $sequence->id)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('%s-%s-%04d', $type->prefix(), $periodKey, $number);
    }

    /**
     * Refuses to issue a number outside a transaction.
     *
     * Without this the gaplessness guarantee is a comment rather than a property: a caller that
     * forgets the transaction gets numbers that survive their own document's failure, and nothing
     * reports it.
     */
    private function assertInTransaction(DocumentType $type): void
    {
        if (! $type->requiresGaplessNumbering()) {
            return;
        }

        if (DB::transactionLevel() < 1) {
            throw BusinessRuleViolation::make(
                code: 'numbering-outside-transaction',
                message: sprintf(
                    'A %s number must be issued inside the transaction that writes the document, or a failed document would consume a number and leave a gap.',
                    strtolower($type->label()),
                ),
            );
        }
    }
}
