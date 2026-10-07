<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Services\SecondFactorSession;
use App\Application\Services\SecondFactorStep;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `two-factor`: after `auth:<guard>` in a realm's route group. Spec section
 * 28: admin requires two-factor authentication. A session that has only
 * passed the password is refused with 403 and a `code` the client switches
 * on. In a realm where two-factor is optional, an account without it passes.
 */
final readonly class RequireSecondFactor
{
    // The only routes an account that must still enroll may use.
    protected const array ENROLLMENT = ['*.auth.two-factor.enroll', '*.auth.two-factor.confirm'];

    public function __construct(
        protected SecondFactorSession $secondFactor,
    ) {
        //
    }

    /**
     * @param  Closure(Request):  Response  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof Administrator && ! $account instanceof User) {
            throw new AuthenticationException;
        }

        $pending = $this->secondFactor->pending($account, UseSessionRealm::realm($request)->twoFactorRequired);

        if ($pending === null) {
            return $next($request);
        }

        if ($pending === SecondFactorStep::Enrollment && $request->routeIs(...self::ENROLLMENT)) {
            return $next($request);
        }

        return new JsonResponse(['message' => $pending->message(), 'code' => $pending->errorCode()], 403);
    }
}
