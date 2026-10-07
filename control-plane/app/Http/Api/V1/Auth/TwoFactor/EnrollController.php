<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

use App\Application\Commands\StartTwoFactorEnrollment;
use App\Application\Exceptions\TwoFactorAlreadyEnabled;
use Illuminate\Http\JsonResponse;

final class EnrollController
{
    /**
     * Start two-factor enrollment
     *
     * Returns a new TOTP secret and its `otpauth://` URI for a QR code.
     * Two-factor is not on until `POST auth/two-factor/confirm` succeeds.
     * Calling this again replaces an unconfirmed secret.
     * Answers 409 when two-factor is already on.
     */
    public function __invoke(AccountRequest $request, StartTwoFactorEnrollment $start): JsonResponse
    {
        try {
            $enrollment = $start->handle($request->account());
        } catch (TwoFactorAlreadyEnabled $exception) {
            abort(409, $exception->getMessage());
        }

        return new JsonResponse(['data' => [
            'secret' => $enrollment->secret,
            'provisioning_uri' => $enrollment->provisioningUri,
        ]]);
    }
}
