<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

use App\Application\Commands\DisableTwoFactor;
use App\Application\Exceptions\IncorrectPassword;
use App\Application\Exceptions\InvalidTwoFactorCode;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class DisableController
{
    /**
     * Disable two-factor authentication
     *
     * Needs the current password and a valid code. Removes the secret and the recovery codes.
     * Where two-factor is mandatory, the account must enroll again before it can use anything else.
     * Limited to 5 attempts per minute for one account.
     */
    #[ApiResponse(204, 'Two-factor authentication is off.')]
    public function __invoke(PasswordAndCodeRequest $request, DisableTwoFactor $disable): Response
    {
        try {
            $disable->handle(
                $request->account(),
                $request->string('password')->toString(),
                $request->string('code')->toString(),
            );
        } catch (IncorrectPassword $exception) {
            throw ValidationException::withMessages(['password' => [$exception->getMessage()]]);
        } catch (InvalidTwoFactorCode $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        return response()->noContent();
    }
}
