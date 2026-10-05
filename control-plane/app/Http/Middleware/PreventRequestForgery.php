<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Realm;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Laravel's CSRF middleware with a CSRF cookie per realm (spec section 28).
 *
 * Laravel names the cookie XSRF-TOKEN and scopes it like the session
 * cookie. Here the session cookie is host-only on the API host, but the
 * SPA on app.<domain> or admin.<domain> must read the CSRF cookie, so it
 * is set on the parent domain. Both apps would then share one name and
 * overwrite each other's token; each realm gets its own.
 */
final class PreventRequestForgery extends Middleware
{
    /**
     * Laravel skips CSRF verification under test. This API never does: its
     * tests go through the real cookie flow.
     */
    protected function runningUnitTests(): bool
    {
        return false;
    }

    /**
     * @param  Request  $request
     * @param  array<string, mixed>  $config
     */
    protected function newCookie($request, $config): Cookie
    {
        $realm = $request->attributes->get(UseSessionRealm::ATTRIBUTE);

        if (! $realm instanceof Realm) {
            throw new LogicException('CSRF protection runs only inside a session realm.');
        }

        return new Cookie(
            name: $realm->csrfCookie,
            value: $request->session()->token(),
            expire: $this->availableAt(60 * $realm->sessionLifetime),
            path: '/',
            domain: $realm->cookieDomain,
            secure: true,
            httpOnly: false,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
