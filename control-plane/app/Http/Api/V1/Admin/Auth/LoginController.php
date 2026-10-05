<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Auth;

use App\Application\Commands\LogIn;
use App\Application\Exceptions\InvalidCredentials;
use App\Domain\Identity\Administrator;
use App\Http\Api\V1\Admin\AdministratorResource;
use App\Http\Api\V1\LoginRequest;
use Illuminate\Validation\ValidationException;

final class LoginController
{
    /**
     * Log in (admin)
     *
     * Starts an administrator session. Call `GET /admin/auth/csrf-cookie` first
     * and send `X-XSRF-TOKEN`. A wrong password and an unknown email produce the same response.
     * Limited to 5 attempts per minute for one email from one address.
     *
     * @unauthenticated
     */
    public function __invoke(LoginRequest $request, LogIn $logIn): AdministratorResource
    {
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

        return new AdministratorResource($administrator);
    }
}
