<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Services\LoginAddressHasher;

// Decision 0013: the address of a failed login for an unknown account is
// not stored. A keyed hash is, so attempts on one address can be correlated.

it('gives one address one hash, whatever its case and the spaces around it', function (): void {
    $hasher = new LoginAddressHasher(str_repeat('k', 32));

    expect($hasher->hash(' Nobody@Example.COM '))->toBe($hasher->hash('nobody@example.com'))
        ->and($hasher->hash('nobody@example.com'))->toMatch('/^[0-9a-f]{64}$/');
});

it('gives different addresses different hashes', function (): void {
    $hasher = new LoginAddressHasher(str_repeat('k', 32));

    expect($hasher->hash('nobody@example.com'))->not->toBe($hasher->hash('somebody@example.com'));
});

it('depends on the key, so the hash cannot be looked up in a dictionary', function (): void {
    $address = 'nobody@example.com';

    expect((new LoginAddressHasher(str_repeat('a', 32)))->hash($address))
        ->not->toBe((new LoginAddressHasher(str_repeat('b', 32)))->hash($address))
        ->not->toBe(hash('sha256', $address));
});
