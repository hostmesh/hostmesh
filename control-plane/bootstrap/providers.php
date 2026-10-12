<?php

// SPDX-License-Identifier: Apache-2.0 AND MIT
// Derived from the Laravel application skeleton (laravel/laravel, MIT).

use App\Providers\AppServiceProvider;
use App\Providers\HostnameServiceProvider;

return [
    HostnameServiceProvider::class,
    AppServiceProvider::class,
];
