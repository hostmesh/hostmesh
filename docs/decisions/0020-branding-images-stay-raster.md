# 0020. Branding images stay raster: no SVG logos

- Status: accepted
- Date: 2026-10-22
- Issue: Branding: SVG logos, after a sanitization decision (#157)

## Context

Spec section 28: branding images are raster only. Logos are PNG, JPEG or WebP, at most 1 MB and 2000×2000; the favicon is a square PNG. "SVG waits for a sanitization decision." This is that decision.

An SVG is a document, not a picture: it can hold script, event handlers, `javascript:` links, `foreignObject` with HTML, references to other URLs and XML entities. What that means depends on where it is shown:

- **In `<img>` and as a favicon,** as both apps show branding, a browser runs no script and loads nothing external. Safe.
- **Opened at its own URL,** the SVG is a document on `api.<domain>`, where a script would act with the API's origin. The image response already sends `Content-Security-Policy: default-src 'none'` and `X-Content-Type-Options: nosniff`, which keep a script from running in current browsers.
- **In emails and invoices,** which section 28 says the same record feeds, and which are not built yet: Gmail and Outlook do not show SVG at all, and a renderer that inlines an SVG or follows its references (a PDF library, for example) would meet everything listed above, on the server.
- **On upload,** the server would parse the SVG. That needs guards against entity expansion and external entities that raster images do not.

Checked on 2026-10-22:

- `enshrined/svg-sanitize`, the PHP sanitizer most projects use (1.1.1, released 2026-10-20, about 2.4 million downloads a month), is GPL-2.0-or-later. The license policy (`infrastructure/hygiene/licenses.toml`) does not allow it, and using it would put the shipped control plane under GPLv3.
- `rhukster/dom-sanitizer` (MIT, 1.0.19 of 2026-09-30, about 330,000 downloads a month) takes its lists from DOMPurify. Its documentation does not say how it treats `<script>`, `javascript:` URLs, `foreignObject` or external references; it would need a review of its source and our own hostile-case tests.
- The PHP image has neither GD nor Imagick, so an upload cannot be turned into a PNG without adding an extension, a system library (librsvg) and the attack surface of an image converter to every installation.
- Uploads are refused unless `finfo` and `getimagesize()` agree on a raster type (`App\Application\Services\ImageInspector`). An SVG, a file named `.svg` and an SVG with an XML declaration are refused today, and `tests/Feature/Api/V1/Admin/BrandingImagesTest.php` covers each.

## Options considered

1. **Sanitize with `enshrined/svg-sanitize`.** Ruled out by its license.
2. **Sanitize with `rhukster/dom-sanitizer`, and serve with `sandbox` added to the policy.** Possible after a review. Every later use of the logo (emails, invoices) would still need a raster copy, and a sanitizer is a dependency whose misses become our vulnerabilities.
3. **Our own allowlist sanitizer** on PHP's DOM: shapes, paths, gradients and text, nothing else, refusing rather than repairing. No dependency, but security code to maintain.
4. **Accept SVG unsanitized,** relying on the response headers and on showing logos in `<img>` only. Safe today, and unsafe the day any context inlines the file.
5. **Convert SVG to PNG on upload.** Needs Imagick and librsvg in every installation.
6. **Keep raster only.**

## Decision

Chosen by the owner on 2026-10-22: option 6. The branding image slots accept PNG, JPEG and WebP logos and a PNG favicon, as now. SVG is refused, whatever the file is called.

- A logo of 2000×2000 is sharp at the sizes the apps show it on high-density screens, and a raster file is what emails and invoices need anyway.
- The response headers of `GET /api/v1/branding/images/{slot}` stay as they are (`nosniff`, `default-src 'none'`), as defence in depth for raster files.

## Consequences

- An administrator with an SVG logo exports it as PNG or WebP first. The upload error names the accepted types.
- Spec section 28 says "SVG waits for a sanitization decision". A `spec-change` issue replaces the sentence with this decision.
- No dependency, extension or parsing code is added.
- **What would reopen this:** a sanitizer under a license the policy allows, reviewed and tested against hostile files, together with a raster copy for emails and invoices (converted at upload, or uploaded alongside). Or a context that needs vector logos and cannot use a large raster image.
