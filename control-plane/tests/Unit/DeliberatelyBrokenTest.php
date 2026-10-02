<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

it('fails on purpose to prove that CI catches a broken test', function (): void {
    expect(true)->toBeFalse();
});
