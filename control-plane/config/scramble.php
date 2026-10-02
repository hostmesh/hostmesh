<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Only the keys Hostmesh sets. Everything else keeps the package default
// (vendor/dedoc/scramble/config/scramble.php).

return [
    // The versioned API. Paths in the document are relative to it.
    'api_path' => 'api/v1',

    // Written by `composer openapi`. Never edit the file by hand.
    'export_path' => base_path('../contracts/api/openapi.json'),

    // Fixed here, not read from env, so every machine generates the same
    // document. `version` follows the URL version, not the release.
    'info' => [
        'version' => 'v1',
        'description' => 'The Hostmesh control plane API.',
    ],

    'ui' => [
        'title' => 'Hostmesh API',
    ],

    // Scramble's browser dev tools follow APP_DEBUG by default.
    'dev_tools' => [
        'enabled' => false,
    ],
];
