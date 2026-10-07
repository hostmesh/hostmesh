<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

use App\Application\Commands\RegenerateRecoveryCodes;
use App\Application\Exceptions\IncorrectPassword;
use App\Application\Exceptions\InvalidTwoFactorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class RecoveryCodesController
{
    /**
     * Regenerate the recovery codes
     *
     * Needs the current password and a valid code. Every earlier recovery code stops working.
     * The new codes in the response are shown only once. Limited to 5 attempts per minute for one account.
     */
    public function __invoke(PasswordAndCodeRequest $request, RegenerateRecoveryCodes $regenerate): JsonResponse
    {
        try {
            $codes = $regenerate->handle(
                $request->account(),
                $request->string('password')->toString(),
                $request->string('code')->toString(),
            );
        } catch (IncorrectPassword $exception) {
            throw ValidationException::withMessages(['password' => [$exception->getMessage()]]);
        } catch (InvalidTwoFactorCode $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        return new JsonResponse(['data' => ['recovery_codes' => $codes]]);
    }
}
