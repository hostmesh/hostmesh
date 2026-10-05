<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Customer\Auth;

use App\Domain\Identity\User;
use App\Http\Api\V1\Customer\UserResource;
use Illuminate\Http\Request;

final class CurrentUserController
{
    /**
     * Current user (portal)
     *
     * The user of the current session.
     */
    public function __invoke(Request $request): UserResource
    {
        $user = $request->user('customer');

        abort_unless($user instanceof User, 401);

        return new UserResource($user);
    }
}
