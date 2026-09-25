<?php

declare(strict_types=1);

namespace LiteParse;

/**
 * A single page rendered to a PNG screenshot.
 */
final class Screenshot
{
    /**
     * @param  ScreenshotRect[]  $rects  Solid rectangles/lines detected in the raster.
     *                                   Empty unless `Config::$detectScreenshotRects` is set.
     */
    public function __construct(
        public readonly int $pageNumber,
        public readonly int $width,
        public readonly int $height,
        /** Raw PNG bytes. */
        public readonly string $bytes,
        /** True when every pixel is the same color (a blank page after render). Always computed. */
        public readonly bool $isSolidFill = false,
        public readonly array $rects = [],
    ) {}
}
