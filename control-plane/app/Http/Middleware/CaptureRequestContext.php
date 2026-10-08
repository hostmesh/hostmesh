<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Services\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first middleware of the `api` group. It notes the IP address and the
 * user agent for the audit log, and gives the request an ID that the
 * response repeats in `X-Request-Id`. The ID is always ours: a value sent
 * by the client would let it write into the audit log.
 */
final readonly class CaptureRequestContext
{
    public const string HEADER = 'X-Request-Id';

    protected const int USER_AGENT_LENGTH = 255;

    public function __construct(
        protected AuditContext $context,
    ) {
        //
    }

    /** @param  Closure(Request): (Response)  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid7();

        $this->context->capture($request->ip(), $this->clean($request->userAgent()), $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /** The header is the client's: make it storable text of a bounded length. */
    protected function clean(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '', mb_scrub($userAgent, 'UTF-8'));

        return $text === '' ? null : mb_substr($text, 0, self::USER_AGENT_LENGTH);
    }
}
