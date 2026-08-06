<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Presentation\Http\Resources;

use Asids\Core\Audit\Domain\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,

            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'high_signal' => $this->event->isHighSignal(),

            'record' => [
                'type' => $this->auditable_type,
                'id' => $this->auditable_id,
            ],

            'changes' => [
                'attributes' => $this->changed_attributes ?? [],
                'before' => $this->old_values,
                'after' => $this->new_values,
            ],

            'actor' => [
                'type' => $this->actor_type->value,
                'type_label' => $this->actor_type->label(),
                'id' => $this->actor_id,
                // The label stored at the time, not the actor's current name — the trail must
                // read correctly years after the account is renamed or deactivated.
                'label' => $this->actor_label,
                'impersonator_id' => $this->impersonator_id,
                'via_access_token' => $this->access_token_id !== null,
            ],

            'context' => [
                'ip_address' => $this->ip_address,
                'channel' => $this->channel,
                'request_method' => $this->request_method,
                'request_url' => $this->request_url,
                'request_id' => $this->request_id,
                'company_id' => $this->company_id,
            ],

            'tags' => $this->tags ?? [],
            'reason' => $this->reason,

            // Surfaced so a reader can tell a verified entry from one in the unsealed tail. An
            // unsealed entry is not less trustworthy, only not yet chained.
            'integrity' => [
                'sealed' => $this->isSealed(),
                'sealed_at' => $this->sealed_at?->toIso8601String(),
                'hash' => $this->hash,
            ],

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
