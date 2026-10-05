<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Support\ContentSecurityPolicy;
use App\Support\Hostnames;
use App\Support\Realms;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Support\ServiceProvider;

/**
 * Spec section 28: every hostname derives from HOSTMESH_DOMAIN. This is the
 * only place that writes a hostname into the configuration.
 */
final class HostnameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $domain = config('hostmesh.domain');
        $hostnames = new Hostnames(is_string($domain) ? $domain : '');

        $this->app->instance(Hostnames::class, $hostnames);

        $this->app->singleton(Realms::class, fn (): Realms => Realms::fromConfig(
            config('hostmesh.realms'), $hostnames,
        ));

        $this->app->singleton(ContentSecurityPolicy::class, fn (): ContentSecurityPolicy => ContentSecurityPolicy::fromConfig(
            config('hostmesh.csp'), $hostnames,
        ));

        config([
            'app.url' => $hostnames->apiOrigin(),
            'cors.allowed_origins' => $hostnames->spaOrigins(),
            'sanctum.stateful' => [$hostnames->host('app'), $hostnames->host('admin')],
            'filesystems.disks.public.url' => $hostnames->apiOrigin().'/storage',
            'mail.mailers.smtp.local_domain' => config('mail.mailers.smtp.local_domain') ?? $hostnames->apiHost(),
        ]);

        // Laravel built the console request from the placeholder app.url
        // before this provider ran. Build it again from the real one, so
        // that URLs generated in commands and queued jobs use the API host.
        if ($this->app->runningInConsole()) {
            (new SetRequestForConsole)->bootstrap($this->app);
        }
    }
}
