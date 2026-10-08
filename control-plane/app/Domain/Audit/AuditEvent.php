<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Carbon\CarbonImmutable;

/**
 * One entry of the audit log (decision 0013, spec section 21). It holds
 * identifiers only: never a name, an email address, a password, a secret,
 * a recovery code or a token. `metadata` says what changed; for a personal
 * field it says that it changed, not its values.
 */
final readonly class AuditEvent
{
    /**
     * @param  string  $resource  a short stable name, for example `administrator`
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function __construct(
        public ActorType $actorType,
        public ?string $actorId,
        public AuditAction $action,
        public string $resource,
        public ?string $resourceId,
        public array $metadata,
        public CarbonImmutable $occurredAt,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {
        //
    }

    /**
     * Something an account did to itself, now.
     *
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public static function onOwnAccount(Administrator|User $account, AuditAction $action, array $metadata = []): self
    {
        $type = ActorType::of($account);

        return new self($type, $account->id, $action, $type->value, $account->id, $metadata, CarbonImmutable::now());
    }

    /** The same event, with where the request came from. */
    public function from(?string $ipAddress, ?string $userAgent, ?string $requestId): self
    {
        return new self(
            $this->actorType,
            $this->actorId,
            $this->action,
            $this->resource,
            $this->resourceId,
            $this->metadata,
            $this->occurredAt,
            $ipAddress,
            $userAgent,
            $requestId,
        );
    }
}
