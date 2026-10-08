<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;

// Decision 0013: an audited change and its audit event commit together.

arch('listeners run inside the request, never on a queue')
    ->expect('App\Application\Listeners')
    ->not->toImplement(ShouldQueue::class);

arch('controllers do not write audit events: commands do, through events')
    ->expect('App\Domain\Audit\AuditLog')
    ->not->toBeUsedIn('App\Http');

arch('the audit log is written by its listener only')
    ->expect('App\Domain\Audit\AuditLog')
    ->toOnlyBeUsedIn(['App\Application\Listeners', 'App\Infrastructure\Audit', 'App\Providers']);
