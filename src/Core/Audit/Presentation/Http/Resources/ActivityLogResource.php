<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Presentation\Http\Resources;

use Asids\Core\Audit\Domain\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
final class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,

            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],

            'causer' => [
                'type' => $this->causer_type,
                'id' => $this->causer_id,
                'label' => $this->causer_label,
            ],

            'properties' => $this->properties ?? [],
            // Lets the UI collapse forty rows from one bulk action into a single feed entry.
            'batch_id' => $this->batch_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
