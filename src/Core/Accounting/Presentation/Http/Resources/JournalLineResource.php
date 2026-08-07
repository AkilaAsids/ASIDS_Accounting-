<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Resources;

use Asids\Core\Accounting\Domain\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalLine
 */
final class JournalLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'account_id' => $this->account_id,
            'branch_id' => $this->branch_id,

            // Emitted as decimal strings, never as numbers. JSON numbers are IEEE-754 doubles in most
            // clients, and a monetary amount that round-trips through one is no longer the amount that
            // was stored — which is the entire reason the ledger stores numeric(19,4).
            'debit' => (string) $this->debit,
            'credit' => (string) $this->credit,
            'side' => $this->side()->value,

            'description' => $this->description,

            // Always null until the FX phase. Present so a client written now does not have to change
            // shape when it starts arriving.
            'transaction_currency_code' => $this->transaction_currency_code,
            'transaction_amount' => $this->transaction_amount === null ? null : (string) $this->transaction_amount,
            'exchange_rate' => $this->exchange_rate === null ? null : (string) $this->exchange_rate,

            'account' => $this->whenLoaded('account', fn (): array => [
                'id' => $this->account->getKey(),
                'code' => $this->account->code,
                'name' => $this->account->name,
                'type' => $this->account->type->value,
            ]),
        ];
    }
}
