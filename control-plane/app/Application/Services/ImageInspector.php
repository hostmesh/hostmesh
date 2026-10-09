<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Branding\InspectedImage;
use finfo;

/**
 * Reads what a file is from its bytes: `finfo` names the type and
 * `getimagesize()` measures it. The two must agree. Neither looks at the
 * file name. This is a header check, not a full decode: what keeps a
 * stored file harmless is that the server names it and serves it as the
 * type found here, with `nosniff`.
 */
final readonly class ImageInspector
{
    /** Null when the file is missing, empty or not an image PHP can measure. */
    public function inspect(string $path): ?InspectedImage
    {
        if (! is_file($path)) {
            return null;
        }

        $bytes = filesize($path);

        if ($bytes === false || $bytes === 0) {
            return null;
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        // Silenced: a damaged header raises a notice, and the answer is the return value.
        $size = @getimagesize($path);

        if ($mimeType === false || $size === false || $size['mime'] !== $mimeType) {
            return null;
        }

        return new InspectedImage($mimeType, $size[0], $size[1], $bytes);
    }
}
