<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Audit;

use App\Domain\Audit\AuditEventRecord;
use App\Http\Api\V1\Concerns\ReportsAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property AuditEventRecord $resource */
final class AuditEventResource extends JsonResource
{
    use ReportsAbilities;

    /**
     * `name` is the display name now, or null when the account is gone or
     * the actor has none. The client shows the placeholder.
     *
     * @return array{id: string, action: string, actor: array{type: string, id: string|null, name: string|null}, resource: array{type: string, id: string|null, name: string|null}, metadata: object, ip_address: string|null, user_agent: string|null, request_id: string|null, created_at: string, abilities: array{view: bool}}
     */
    public function toArray(Request $request): array
    {
        $event = $this->resource;

        return [
            'id' => $event->id,
            'action' => $event->action,
            'actor' => [
                'type' => $event->actor_type,
                'id' => $event->actor_id,
                'name' => $event->actorName,
            ],
            'resource' => [
                'type' => $event->resource,
                'id' => $event->resource_id,
                'name' => $event->resourceName,
            ],
            /** @var array<string, bool|int|string|null> */
            'metadata' => (object) $event->metadata,
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
            'request_id' => $event->request_id,
            'created_at' => $event->created_at->toIso8601String(),
            'abilities' => [
                'view' => $this->allows($request, 'view'),
            ],
        ];
    }
}
