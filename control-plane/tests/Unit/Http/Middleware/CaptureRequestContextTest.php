<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Services\AuditContext;
use App\Http\Middleware\CaptureRequestContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

// Issue #27: where a request came from, for the audit log.

/** @param  array<string, string>  $server */
function capture(AuditContext $context, array $server = []): Response
{
    $request = Request::create('/api/v1/health', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7'] + $server);

    return (new CaptureRequestContext($context))->handle($request, fn (): Response => new Response('ok'));
}

it('captures the IP address and the user agent', function (): void {
    $context = new AuditContext;

    capture($context, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/143.0']);

    expect($context->ipAddress())->toBe('203.0.113.7')
        ->and($context->userAgent())->toBe('Mozilla/5.0 (X11; Linux x86_64) Firefox/143.0');
});

it('gives every request its own ID and returns it in a header', function (): void {
    $context = new AuditContext;

    $first = capture($context);
    $firstId = $context->requestId();
    $second = capture($context);

    expect(Str::isUuid($firstId))->toBeTrue()
        ->and($first->headers->get('X-Request-Id'))->toBe($firstId)
        ->and($second->headers->get('X-Request-Id'))->toBe($context->requestId())
        ->and($context->requestId())->not->toBe($firstId);
});

it('does not take a request ID from the client', function (): void {
    $context = new AuditContext;

    $response = capture($context, ['HTTP_X_REQUEST_ID' => 'chosen-by-the-client']);

    expect($context->requestId())->not->toBe('chosen-by-the-client')
        ->and($response->headers->get('X-Request-Id'))->toBe($context->requestId());
});

it('has no user agent when the client sent none', function (string $userAgent): void {
    $context = new AuditContext;

    capture($context, ['HTTP_USER_AGENT' => $userAgent]);

    expect($context->userAgent())->toBeNull();
})->with(['empty' => [''], 'control characters only' => ["\x00\n\t"]]);

it('cuts a long user agent to 255 characters', function (): void {
    $context = new AuditContext;

    capture($context, ['HTTP_USER_AGENT' => str_repeat('é', 2000)]);

    expect(mb_strlen((string) $context->userAgent()))->toBe(255);
});

it('stores a user agent that is not clean text as valid text without control characters', function (): void {
    $context = new AuditContext;

    capture($context, ['HTTP_USER_AGENT' => "Bad\xC3\x28Agent\x00\r\nInjected: 1\x7F"]);

    $userAgent = (string) $context->userAgent();

    expect(mb_check_encoding($userAgent, 'UTF-8'))->toBeTrue()
        ->and(preg_match('/[\x00-\x1F\x7F]/', $userAgent))->toBe(0)
        ->and($userAgent)->toContain('Bad')->toContain('Agent');
});

it('knows nothing outside a request', function (): void {
    $context = new AuditContext;

    expect($context->ipAddress())->toBeNull()
        ->and($context->userAgent())->toBeNull()
        ->and($context->requestId())->toBeNull();
});
