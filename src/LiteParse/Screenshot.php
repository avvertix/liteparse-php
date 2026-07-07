<?php

declare(strict_types=1);

namespace LiteParse;

/**
 * A single page rendered to a PNG screenshot.
 */
final class Screenshot
{
    public function __construct(
        public readonly int $pageNumber,
        public readonly int $width,
        public readonly int $height,
        /** Raw PNG bytes. */
        public readonly string $bytes,
    ) {}
}
