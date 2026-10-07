<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

final class PasswordAndCodeRequest extends AccountRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:16'],
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
