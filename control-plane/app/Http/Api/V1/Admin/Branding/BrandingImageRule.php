<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Exceptions\InvalidBrandingImage;
use App\Application\Services\ImageInspector;
use App\Domain\Branding\ImageSlot;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Turns the rules of a slot into a validation error. It reads the bytes of
 * the upload and never its client name or client type. The slot is resolved
 * when the rule runs: the rules are also read without a route, to document them.
 */
final readonly class BrandingImageRule implements ValidationRule
{
    /** @param  Closure(): ImageSlot  $slot */
    public function __construct(
        protected Closure $slot,
        protected ImageInspector $inspector,
    ) {
        //
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The upload failed.');

            return;
        }

        $image = $this->inspector->inspect((string) $value->getRealPath());

        if ($image === null) {
            $fail(InvalidBrandingImage::NOT_AN_IMAGE);

            return;
        }

        $violation = ($this->slot)()->violation($image);

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
