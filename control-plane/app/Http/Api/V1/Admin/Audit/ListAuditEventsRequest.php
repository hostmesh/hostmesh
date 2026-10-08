<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Audit;

use App\Application\Queries\AuditEventFilter;
use App\Application\Queries\ListAuditEvents;
use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEventRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ListAuditEventsRequest extends FormRequest
{
    /** Runs before validation: an account that may not read the log learns nothing about its filters. */
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('viewAny', AuditEventRecord::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'actor_type' => ['sometimes', Rule::enum(ActorType::class)],
            'actor_id' => ['sometimes', 'uuid'],
            'resource' => ['sometimes', 'string', 'max:64'],
            'resource_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', Rule::enum(AuditAction::class)],
            'from' => ['sometimes', 'date'],
            // Compared with `from` only when there is one: on its own,
            // `after_or_equal:from` would refuse every `to`.
            'to' => ['sometimes', 'date', Rule::when($this->filled('from'), ['after_or_equal:from'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ];
    }

    public function filter(): AuditEventFilter
    {
        return new AuditEventFilter(
            actorType: $this->enum('actor_type', ActorType::class),
            actorId: $this->has('actor_id') ? $this->string('actor_id')->toString() : null,
            resource: $this->has('resource') ? $this->string('resource')->toString() : null,
            resourceId: $this->has('resource_id') ? $this->string('resource_id')->toString() : null,
            action: $this->enum('action', AuditAction::class),
            from: $this->date('from')?->toImmutable(),
            to: $this->date('to')?->toImmutable(),
        );
    }

    public function perPage(): int
    {
        return $this->integer('per_page', ListAuditEvents::DEFAULT_PER_PAGE);
    }

    public function encodedCursor(): ?string
    {
        return $this->has('cursor') ? $this->string('cursor')->toString() : null;
    }
}
