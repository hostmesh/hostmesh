<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored audit event, for reading (decision 0013). Events are written
 * through AuditLog only, and the database refuses to change or delete one.
 *
 * @property string $id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string $resource
 * @property string|null $resource_id
 * @property array<string, mixed> $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
final class AuditEventRecord extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * Display names of the actor and the resource. Not stored: the query
     * that lists events resolves them at read time, and they stay null
     * when the account is gone.
     */
    public ?string $actorName = null;

    public ?string $resourceName = null;

    protected $table = 'audit_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
