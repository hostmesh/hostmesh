<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Carbon\CarbonImmutable;

/**
 * A login was refused. For an unknown address there is no account, and the
 * event carries the keyed hash of the address instead of the address
 * (decision 0013).
 */
final readonly class LoginFailed implements Audited
{
    /**
     * @param  ActorType  $accountType  the kind of account the realm logs in
     * @param  string|null  $addressHash  from LoginAddressHasher, for an unknown address only
     */
    public function __construct(
        public ActorType $accountType,
        public Administrator|User|null $account,
        public ?string $addressHash,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return new AuditEvent(
            $this->accountType,
            $this->account?->id,
            AuditAction::LoginFailed,
            $this->accountType->value,
            $this->account?->id,
            $this->account === null && $this->addressHash !== null ? ['email_hash' => $this->addressHash] : [],
            CarbonImmutable::now(),
        );
    }
}
