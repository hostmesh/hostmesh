<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Auth;

use App\Domain\Identity\Administrator;
use App\Http\Api\V1\Admin\AdministratorResource;
use Illuminate\Http\Request;

final class CurrentUserController
{
    /**
     * Current user (admin)
     *
     * The administrator of the current session.
     */
    public function __invoke(Request $request): AdministratorResource
    {
        $administrator = $request->user('admin');

        abort_unless($administrator instanceof Administrator, 401);

        return new AdministratorResource($administrator);
    }
}
