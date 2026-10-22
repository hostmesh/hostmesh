<?php

// SPDX-License-Identifier: Apache-2.0 AND MIT
// Derived from the Laravel application skeleton (laravel/laravel, MIT).

use App\Application\Commands\DispatchOneShotJob;
use App\Application\Jobs\ClearAuditAddresses;
use App\Application\Jobs\PruneAuditEvents;
use App\Application\Jobs\SweepJobHistory;
use App\Domain\Audit\RetentionPolicy;
use App\Domain\Provisioning\SweepPolicy;
use App\Http\Middleware\CaptureRequestContext;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\UseSessionRealm;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First in the group, so that a request refused further in still
        // gets its X-Request-Id.
        $middleware->api(prepend: [CaptureRequestContext::class]);

        // Decision 0018: from a trusted proxy (HOSTMESH_TRUSTED_PROXIES),
        // only the client address. Host, scheme, port and prefix stay what
        // the web server says.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR);

        $middleware->alias([
            // The first middleware of each realm's route group (routes/api.php).
            'realm' => UseSessionRealm::class,
            // After auth:<guard>: the session must have completed the second factor.
            'two-factor' => RequireSecondFactor::class,
        ]);

        // The gate answers before a route parameter is resolved, so half a
        // login learns nothing about what exists.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RequireSecondFactor::class,
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Spec section 21: the audit log's retention, daily. Each run goes
        // through the job infrastructure and leaves a row in job_history.
        // Not limited to one server: that is a cache lock (spec section
        // 24). Two schedulers would run a job twice, which changes nothing.
        foreach ([PruneAuditEvents::class, ClearAuditAddresses::class] as $job) {
            $schedule->call(fn (DispatchOneShotJob $dispatch) => $dispatch->handle(
                $job,
                RetentionPolicy::RESOURCE_TYPE,
                RetentionPolicy::RESOURCE_ID,
            ))->name($job::type()->value)->dailyAt('03:00');
        }

        // Issue #155: gives a new job to rows whose job Redis lost.
        $schedule->call(fn (DispatchOneShotJob $dispatch) => $dispatch->handle(
            SweepJobHistory::class,
            SweepPolicy::RESOURCE_TYPE,
            SweepPolicy::RESOURCE_ID,
        ))->name(SweepJobHistory::type()->value)->everyFiveMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
