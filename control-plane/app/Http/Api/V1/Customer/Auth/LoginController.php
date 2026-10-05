<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Customer\Auth;

use App\Application\Commands\LogIn;
use App\Application\Exceptions\InvalidCredentials;
use App\Domain\Identity\User;
use App\Http\Api\V1\Customer\UserResource;
use App\Http\Api\V1\LoginRequest;
use Illuminate\Validation\ValidationException;

final class LoginController
{
    /**
     * Log in (portal)
     *
     * Starts a customer session. Call `GET /customer/auth/csrf-cookie` first and send `X-XSRF-TOKEN`. A wrong password and an unknown email produce the same response. Limited to 5 attempts per minute for one email from one address.
     *
     * @unauthenticated
     */
    public function __invoke(LoginRequest $request, LogIn $logIn): UserResource
    {
        try {
            $user = $logIn->handle(
                'customer',
                $request->string('email')->toString(),
                $request->string('password')->toString(),
            );
        } catch (InvalidCredentials $exception) {
            throw ValidationException::withMessages(['email' => [$exception->getMessage()]]);
        }

        abort_unless($user instanceof User, 401);

        return new UserResource($user);
    }
}
