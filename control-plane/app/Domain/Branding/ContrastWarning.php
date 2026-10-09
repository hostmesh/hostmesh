<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding;

/**
 * A pair of colors that fails WCAG AA. It warns and never blocks a save
 * (spec section 28).
 */
final readonly class ContrastWarning
{
    /**
     * @param  string  $color  `primary` or `primary_foreground`
     * @param  string  $against  `primary`, `background` or `card`
     */
    public function __construct(
        public ColorMode $mode,
        public string $color,
        public string $against,
        public float $ratio,
        public float $required,
    ) {
        //
    }

    /** @return array{mode: string, color: string, against: string, ratio: float, required: float} */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'color' => $this->color,
            'against' => $this->against,
            'ratio' => $this->ratio,
            'required' => $this->required,
        ];
    }
}
