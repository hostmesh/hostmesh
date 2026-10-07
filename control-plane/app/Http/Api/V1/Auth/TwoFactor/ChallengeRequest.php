<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

final class ChallengeRequest extends AccountRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'prohibits:code', 'string', 'max:64'],
        ];
    }

    /** Authenticator apps show "123 456". */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => str_replace(' ', '', $code)]);
        }
    }
}
