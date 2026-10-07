<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\TwoFactor\RecoveryCodes;

it('generates ten distinct codes of four groups of five characters', function (): void {
    $codes = (new RecoveryCodes)->generate();

    expect($codes)->toHaveCount(10)
        ->and(array_unique($codes))->toHaveCount(10);

    foreach ($codes as $code) {
        // No I, O, 0 or 1: they are misread on paper.
        expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{5}(-[A-HJ-NP-Z2-9]{5}){3}$/');
    }
});

it('hashes a code to SHA-256 hex and never returns the code', function (): void {
    $hash = (new RecoveryCodes)->hash('AAAAA-BBBBB-CCCCC-DDDDD');

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->toBe(hash('sha256', 'AAAAABBBBBCCCCCDDDDD'));
});

it('hashes a code the same however it is typed', function (string $typed): void {
    $codes = new RecoveryCodes;

    expect($codes->hash($typed))->toBe($codes->hash('AAAAA-BBBBB-CCCCC-DDDDD'));
})->with([
    'lower case' => ['aaaaa-bbbbb-ccccc-ddddd'],
    'no dashes' => ['AAAAABBBBBCCCCCDDDDD'],
    'spaces' => [' AAAAA BBBBB CCCCC DDDDD '],
]);
