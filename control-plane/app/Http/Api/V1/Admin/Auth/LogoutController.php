<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Auth;

use App\Application\Commands\LogOut;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController
{
    /**
     * Log out (admin)
     *
     * Ends the administrator session. The session ID stops working on the server.
     */
    #[ApiResponse(204, 'The session has ended.')]
    public function __invoke(Request $request, LogOut $logOut): Response
    {
        $logOut->handle('admin');

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
