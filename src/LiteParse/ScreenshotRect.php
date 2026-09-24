<?php

declare(strict_types=1);

namespace LiteParse;

/**
 * One solid rectangle (or thick line) detected in a rendered page screenshot,
 * from `Screenshot::$rects`. Detection runs on the raster, so it also finds
 * structure in scanned/flattened pages that carry no vector paths.
 */
final class ScreenshotRect
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
        /** Fill color as ARGB hex string (e.g. "ff1a2b3c"). */
        public readonly string $color,
        /** True when only one dimension reaches the minimum rectangle size (a line, not a filled area). */
        public readonly bool $isLine,
    ) {}

    /**
     * @param  array{x: float, y: float, width: float, height: float, color: string, is_line: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            x: $data['x'],
            y: $data['y'],
            width: $data['width'],
            height: $data['height'],
            color: $data['color'],
            isLine: $data['is_line'],
        );
    }
}
