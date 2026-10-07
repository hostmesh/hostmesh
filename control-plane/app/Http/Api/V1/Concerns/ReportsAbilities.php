<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The `abilities` block of an API Resource (decision 0012, spec section 28):
 * what the current user may do with this one record, answered by the same
 * Gate and policy that guard the endpoints. See "Authorization" in
 * control-plane/README.md for the convention.
 *
 * @mixin JsonResource
 */
trait ReportsAbilities
{
    /**
     * One entry of `abilities`: may the current user do this with the record?
     *
     * @param  string  $ability  a method of the record's policy, for example `view` or `manageTwoFactor`
     */
    protected function allows(Request $request, string $ability): bool
    {
        return Gate::forUser($request->user())->allows($ability, $this->resource);
    }
}
