<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

$strictPolicy = [
    'default-src' => ["'none'"],
    'script-src' => ["'self'"],
    'style-src' => ["'self'"],
    // {api}: logos and the favicon are served by the API (spec section 28, "Branding").
    'img-src' => ["'self'", 'data:', '{api}'],
    'font-src' => ["'self'"],
    // {api} is replaced by the API origin (App\Support\ContentSecurityPolicy).
    'connect-src' => ['{api}'],
    'manifest-src' => ["'self'"],
    'base-uri' => ["'none'"],
    'form-action' => ["'none'"],
    'frame-ancestors' => ["'none'"],
];

return [
    // The registrable domain of the installation, for example example.com.
    // This is the only hostname setting (spec section 28): app., admin.,
    // api. and agent. are derived from it by App\Support\Hostnames, and
    // HostnameServiceProvider writes the dependent config values.
    //
    // The default is the development domain. `composer install` boots the
    // application before a .env exists, so there must be one; .test is a
    // reserved top-level domain, so an installation that forgets the setting
    // refuses every real browser rather than trusting a foreign origin. A
    // malformed value stops the application at boot.
    'domain' => env('HOSTMESH_DOMAIN', 'hostmesh.test'),

    // The proxies in front of the control plane, such as a CDN's ranges:
    // IP addresses and CIDR ranges, separated by commas (decision 0018).
    // From these, and only these, the client address is read from
    // X-Forwarded-For; every other request keeps the address that connected.
    // None by default. A malformed value, or one that would trust every
    // address, stops the application at boot (App\Support\TrustedProxies).
    'trusted_proxies' => env('HOSTMESH_TRUSTED_PROXIES', ''),

    // One realm per route group (spec section 28): its own guard, session
    // cookie, session table and CSRF cookie. Lifetimes are idle minutes.
    // Two-factor authentication is mandatory where two_factor_required is true (spec section 28: admin).
    // It is not an environment setting.
    'realms' => [
        'admin' => [
            'guard' => 'admin',
            'subdomain' => 'admin',
            'session_cookie' => '__Host-hostmesh-admin-session',
            'session_table' => 'admin_sessions',
            'csrf_cookie' => 'XSRF-TOKEN-ADMIN',
            'session_lifetime' => (int) env('HOSTMESH_ADMIN_SESSION_LIFETIME', 30),
            'two_factor_required' => true,
        ],
        'customer' => [
            'guard' => 'customer',
            'subdomain' => 'app',
            'session_cookie' => '__Host-hostmesh-portal-session',
            'session_table' => 'portal_sessions',
            'csrf_cookie' => 'XSRF-TOKEN-PORTAL',
            'session_lifetime' => (int) env('HOSTMESH_PORTAL_SESSION_LIFETIME', 120),
            'two_factor_required' => false,
        ],
    ],

    // Content Security Policy of the two static apps. The web server sends
    // the header (infrastructure/nginx); a test keeps the example in step.
    'csp' => [
        'portal' => $strictPolicy,
        'admin' => $strictPolicy,
    ],

    // One-shot jobs (spec section 8, decision 0014). Delays are in seconds.
    // A retryable failure waits base_delay, then twice as long each time,
    // never longer than max_delay, and fails after max_attempts. Chance
    // shortens a delay by up to the jitter share (0 to 1). A waiting job
    // looks again after recheck_delay unless it names its own.
    'jobs' => [
        'retry' => [
            'base_delay' => (int) env('HOSTMESH_JOB_RETRY_BASE_DELAY', 10),
            'max_delay' => (int) env('HOSTMESH_JOB_RETRY_MAX_DELAY', 600),
            'max_attempts' => (int) env('HOSTMESH_JOB_RETRY_MAX_ATTEMPTS', 8),
            'jitter' => (float) env('HOSTMESH_JOB_RETRY_JITTER', 0.5),
        ],
        'recheck_delay' => (int) env('HOSTMESH_JOB_RECHECK_DELAY', 120),
    ],
];
