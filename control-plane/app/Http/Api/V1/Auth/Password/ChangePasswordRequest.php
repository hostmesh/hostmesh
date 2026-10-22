<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\Password;

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    /** bcrypt reads no further. */
    public const int MAX_BYTES = 72;

    public function authorize(): bool
    {
        $account = $this->account();

        return Gate::forUser($account)->allows('changePassword', $account);
    }

    /**
     * Decision 0023: 15 characters to 72 bytes, no rule about which
     * characters, and none that Pwned Passwords knows (NIST SP 800-63B-4).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rule = Password::min(15);

        if (config()->boolean('hostmesh.passwords.breach_check')) {
            $rule = $rule->uncompromised();
        }

        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', $rule, $this->fitsBcrypt(...)],
            // Required where two-factor is on; the application command decides.
            'code' => ['nullable', 'string', 'max:16'],
        ];
    }

    public function account(): Administrator|User
    {
        $account = $this->user();

        abort_unless($account instanceof Administrator || $account instanceof User, 401);

        return $account;
    }

    public function code(): ?string
    {
        $code = $this->input('code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** Authenticator apps show "123 456". */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => str_replace(' ', '', $code)]);
        }
    }

    protected function fitsBcrypt(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > self::MAX_BYTES) {
            $fail('The new password is too long: at most 72 bytes, which is 72 plain letters and digits, or fewer accented letters and symbols.');
        }
    }
}
