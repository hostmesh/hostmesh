<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/** A request whose rules cannot be read without a running request. */
final class ThrowingRulesRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:'.$this->limit()]];
    }

    private function limit(): int
    {
        throw new LogicException('rules() needs the signed-in user');
    }
}
