<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Throwaway (issue #105): proves that a failing suite turns its gate red.
it('fails on purpose', function (): void {
    expect(true)->toBeFalse();
});
