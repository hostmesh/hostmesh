<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Served under /api/v1 (see bootstrap.app).

use App\Http\Api\V1\Admin;
use App\Http\Api\V1\Customer;
use App\Http\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Spec section 28: two realms. Each has its own SPA origin, guard, session
// cookie and CSRF cookie, set up by the `realm` middleware
// (App\Http\Middleware\UseSessionRealm). Everything for a realm goes inside
// its group; nothing outside a group has a session.

Route::prefix('admin')->name('admin.')->middleware('realm:admin')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('/csrf-cookie', Admin\Auth\CsrfCookieController::class)->name('csrf-cookie');
        Route::post('/login', Admin\Auth\LoginController::class)->middleware('throttle:login')->name('login');

        Route::middleware('auth:admin')->group(function (): void {
            Route::post('/logout', Admin\Auth\LogoutController::class)->name('logout');
            Route::get('/user', Admin\Auth\CurrentUserController::class)->name('user');
        });
    });
});

Route::prefix('customer')->name('customer.')->middleware('realm:customer')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('/csrf-cookie', Customer\Auth\CsrfCookieController::class)->name('csrf-cookie');
        Route::post('/login', Customer\Auth\LoginController::class)->middleware('throttle:login')->name('login');

        Route::middleware('auth:customer')->group(function (): void {
            Route::post('/logout', Customer\Auth\LogoutController::class)->name('logout');
            Route::get('/user', Customer\Auth\CurrentUserController::class)->name('user');
        });
    });
});
