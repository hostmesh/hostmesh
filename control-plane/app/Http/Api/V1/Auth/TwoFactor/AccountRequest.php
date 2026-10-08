<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Auth\TwoFactor;

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A request of the logged-in account of either realm to manage its own
 * second factor. The realm middleware has made the realm's guard the
 * default one.
 */
class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->account();

        return Gate::forUser($account)->allows('manageTwoFactor', $account);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    public function account(): Administrator|User
    {
        $account = $this->user();

        abort_unless($account instanceof Administrator || $account instanceof User, 401);

        return $account;
    }
}
