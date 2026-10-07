<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

use App\Application\Commands\CompleteTwoFactorChallenge;
use App\Application\Exceptions\InvalidTwoFactorCode;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class ChallengeController
{
    /**
     * Complete the two-factor challenge
     *
     * The second step of a login: send `code` from the authenticator app, or `recovery_code`, which
     * works once. A code is refused when it was already used. Limited to 5 attempts per minute for one account.
     */
    #[ApiResponse(204, 'The second factor is complete for this session.')]
    public function __invoke(ChallengeRequest $request, CompleteTwoFactorChallenge $challenge): Response
    {
        $code = $request->has('code') ? $request->string('code')->toString() : null;
        $recoveryCode = $request->has('recovery_code') ? $request->string('recovery_code')->toString() : null;

        try {
            $challenge->handle($request->account(), $code, $recoveryCode);
        } catch (InvalidTwoFactorCode $exception) {
            throw ValidationException::withMessages([
                $code !== null ? 'code' : 'recovery_code' => [$exception->getMessage()],
            ]);
        }

        return response()->noContent();
    }
}
