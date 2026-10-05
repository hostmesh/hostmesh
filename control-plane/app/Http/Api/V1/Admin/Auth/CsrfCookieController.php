<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Auth;

use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Response;

final class CsrfCookieController
{
    /**
     * Start the CSRF cookie flow (admin)
     *
     * Sets the admin session cookie and the `XSRF-TOKEN-ADMIN` cookie.
     * The admin app sends the value of that cookie in the `X-XSRF-TOKEN`
     * header of every state-changing request. Call it once before logging in.
     * Only the admin origin may call it.
     *
     * @unauthenticated
     */
    #[ApiResponse(204, 'The cookies are set.')]
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
