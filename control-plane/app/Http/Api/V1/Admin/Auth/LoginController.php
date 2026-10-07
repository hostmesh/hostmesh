<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Auth;

use App\Application\Commands\LogIn;
use App\Application\Exceptions\InvalidCredentials;
use App\Application\Services\SecondFactorSession;
use App\Domain\Identity\Administrator;
use App\Http\Api\V1\Admin\AdministratorResource;
use App\Http\Api\V1\LoginRequest;
use App\Http\Middleware\UseSessionRealm;
use Illuminate\Validation\ValidationException;

final class LoginController
{
    /**
     * Log in (admin)
     *
     * Starts an administrator session. Call `GET /admin/auth/csrf-cookie` first
     * and send `X-XSRF-TOKEN`. A wrong password and an unknown email produce the same response.
     * Limited to 5 attempts per minute for one email from one address.
     * The session is complete only after the step in `meta.second_factor`: `challenge`
     * (send a code to `auth/two-factor/challenge`) or `enrollment` (set up two-factor first).
     *
     * @unauthenticated
     */
    public function __invoke(
        LoginRequest $request,
        LogIn $logIn,
        SecondFactorSession $secondFactor
    ): AdministratorResource {
        try {
            $administrator = $logIn->handle(
                'admin',
                $request->string('email')->toString(),
                $request->string('password')->toString(),
            );
        } catch (InvalidCredentials $exception) {
            throw ValidationException::withMessages(['email' => [$exception->getMessage()]]);
        }

        abort_unless($administrator instanceof Administrator, 401);

        $pending = $secondFactor->pending($administrator, UseSessionRealm::realm($request)->twoFactorRequired);

        return (new AdministratorResource($administrator))
            ->additional(['meta' => ['second_factor' => $pending->value ?? 'none']]);
    }
}
