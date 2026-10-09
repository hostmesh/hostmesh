<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Commands\BrandingChanges;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\HexColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class UpdateBrandingRequest extends FormRequest
{
    /** Runs before validation: an account that may not edit branding learns nothing about its fields. */
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('update', new BrandingRecord);
    }

    /**
     * PUT replaces the record: every field must be present, and null
     * clears a color or a link.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // `#rrggbb`: no shorthand and no alpha channel.
        $color = ['present', 'nullable', 'string', 'regex:'.HexColor::PATTERN];
        // `https` only: a link is shown to customers and put into emails.
        $link = ['present', 'nullable', 'string', 'max:2048', 'url:https'];

        return [
            // At least one visible character and no control characters: the
            // name goes into page titles and email subjects.
            'product_name' => ['required', 'string', 'max:60', 'regex:/\A(?=.*\S)[^\p{C}]+\z/u'],
            'colors.light.primary' => $color,
            'colors.light.primary_foreground' => $color,
            'colors.dark.primary' => $color,
            'colors.dark.primary_foreground' => $color,
            'links.support' => $link,
            'links.terms' => $link,
            'links.privacy' => $link,
        ];
    }

    public function changes(): BrandingChanges
    {
        return new BrandingChanges(
            productName: $this->string('product_name')->toString(),
            primaryLight: $this->optional('colors.light.primary'),
            primaryForegroundLight: $this->optional('colors.light.primary_foreground'),
            primaryDark: $this->optional('colors.dark.primary'),
            primaryForegroundDark: $this->optional('colors.dark.primary_foreground'),
            supportUrl: $this->optional('links.support'),
            termsUrl: $this->optional('links.terms'),
            privacyUrl: $this->optional('links.privacy'),
        );
    }

    protected function optional(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }
}
