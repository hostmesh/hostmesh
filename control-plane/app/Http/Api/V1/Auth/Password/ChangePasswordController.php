<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\Password;

use App\Application\Commands\ChangePassword;
use App\Application\Exceptions\IncorrectPassword;
use App\Application\Exceptions\InvalidTwoFactorCode;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class ChangePasswordController
{
    /**
     * Change the password
     *
     * Needs the current password, and a valid code where two-factor is on (always in admin). The new password has
     * 15 characters to 72 bytes and must not be known from breaches. The account's other sessions end; this one goes
     * on. Limited to 5 attempts per minute for one account.
     */
    #[ApiResponse(204, 'The password is changed.')]
    public function __invoke(ChangePasswordRequest $request, ChangePassword $change): Response
    {
        try {
            $change->handle(
                $request->account(),
                $request->string('current_password')->toString(),
                $request->string('new_password')->toString(),
                $request->code(),
                $request->session()->getId(),
            );
        } catch (IncorrectPassword $exception) {
            throw ValidationException::withMessages(['current_password' => [$exception->getMessage()]]);
        } catch (InvalidTwoFactorCode $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        return response()->noContent();
    }
}
