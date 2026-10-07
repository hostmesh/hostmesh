<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Realm;
use App\Support\Realms;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The first middleware of a realm's route group (`realm:admin`,
 * `realm:customer`). Spec section 28: admin and portal sessions are
 * isolated, each with its own guard and session cookie.
 *
 * Sanctum decides statefulness from one list of hosts and reads one global
 * session configuration. This middleware makes both specific to the route
 * group: it accepts only the realm's own SPA origin, points the session at
 * the realm's cookie, table and lifetime, and then runs Sanctum's pipeline
 * (cookie encryption, session, CSRF, session authentication).
 */
final class UseSessionRealm extends EnsureFrontendRequestsAreStateful
{
    public const string ATTRIBUTE = 'hostmesh.realm';

    public function __construct(
        protected readonly Realms $realms,
        protected readonly SessionManager $sessions,
        protected readonly AuthManager $auth,
        protected readonly Application $app,
    ) {
        //
    }

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  callable  $next
     */
    public function handle($request, $next, string $realm = ''): Response
    {
        $settings = $this->realms->get($realm);

        // Both apps are cross-origin to the API, so a browser always sends
        // Origin. A browser also sends the admin session cookie on requests
        // from the portal (same site): the origin check is what keeps a
        // script on one app away from the other realm.
        if ($request->headers->get('Origin') !== $settings->origin) {
            throw new AccessDeniedHttpException('This origin may not use this API.');
        }

        config([
            'session.cookie' => $settings->sessionCookie,
            'session.table' => $settings->sessionTable,
            'session.lifetime' => $settings->sessionLifetime,
            'session.path' => '/',
            'session.domain' => null,
            'session.secure' => true,
            'sanctum.guard' => [$settings->guard],
        ]);

        // The session driver, the session.store singleton and the guards
        // capture the session configuration when they are built. Under
        // PHP-FPM nothing is built yet; in tests and under a long-running
        // server the previous request's instances must go.
        $this->sessions->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->auth->forgetGuards();
        $this->auth->shouldUse($settings->guard);

        $request->attributes->set(self::ATTRIBUTE, $settings);

        return parent::handle($request, $next);
    }

    /**
     * The origin was checked in handle(); Sanctum's host list is not used.
     *
     * @param  Request  $request
     */
    public static function fromFrontend($request): bool
    {
        return $request->attributes->has(self::ATTRIBUTE);
    }

    /** The realm of a request inside a realm route group. */
    public static function realm(Request $request): Realm
    {
        $realm = $request->attributes->get(self::ATTRIBUTE);

        return $realm instanceof Realm ? $realm : throw new LogicException('This route is outside the realm route groups.');
    }
}
