<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Services\ImageInspector;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\ImageSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StoreBrandingImageRequest extends FormRequest
{
    /** Runs first: an account that may not edit branding learns nothing, not even which slots exist. */
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('update', new BrandingRecord);
    }

    /** @return array<string, list<mixed>> */
    public function rules(ImageInspector $inspector): array
    {
        return [
            'file' => ['required', 'file', new BrandingImageRule($this->slot(...), $inspector)],
        ];
    }

    /**
     * The slot of the route. The route takes any string, so that the
     * policy answers before the slot is looked at.
     */
    public function slot(): ImageSlot
    {
        $slot = $this->route('slot');

        return ImageSlot::tryFrom(is_string($slot) ? $slot : '') ?? throw new NotFoundHttpException;
    }

    /** The temporary file of the validated upload. Not `path()`: that is the request path. */
    public function uploadedPath(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? (string) $file->getRealPath() : '';
    }
}
