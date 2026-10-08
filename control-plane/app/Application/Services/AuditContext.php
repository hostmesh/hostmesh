<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

/**
 * Where the current request came from (decision 0013, amended). Filled by
 * App\Http\Middleware\CaptureRequestContext, read when an audit event is
 * written. Everything is null outside a request, for example in a console
 * command.
 */
final class AuditContext
{
    protected ?string $ipAddress = null;

    protected ?string $userAgent = null;

    protected ?string $requestId = null;

    public function capture(?string $ipAddress, ?string $userAgent, string $requestId): void
    {
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->requestId = $requestId;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }
}
