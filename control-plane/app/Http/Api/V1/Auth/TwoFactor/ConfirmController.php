<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

use App\Application\Commands\ConfirmTwoFactorEnrollment;
use App\Application\Exceptions\InvalidTwoFactorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class ConfirmController
{
    /**
     * Confirm two-factor enrollment
     *
     * Turns two-factor on with a code from the authenticator app and completes the second factor
     * for this session. The recovery codes in the response are shown only once.
     */
    public function __invoke(CodeRequest $request, ConfirmTwoFactorEnrollment $confirm): JsonResponse
    {
        try {
            $codes = $confirm->handle($request->account(), $request->string('code')->toString());
        } catch (InvalidTwoFactorCode $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        return new JsonResponse(['data' => ['recovery_codes' => $codes]]);
    }
}
