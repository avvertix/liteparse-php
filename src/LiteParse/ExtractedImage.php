<?php

declare(strict_types=1);

namespace LiteParse;

/**
 * Metadata for one embedded image extracted from the document, returned by
 * `ParseResult::images()` when `Config::$extractImages` is on. Pixel bytes
 * are never carried here — write them via `Config::$imageOutputDir` and read
 * `$path`, or fall back to `Config::$imageMode = ImageMode::Embed` if you
 * need bytes without a directory (not yet surfaced by this binding).
 *
 * `$id`/`$format` match a `figure` `Layout\Block`'s own `$id`/`$format` and
 * the `img_{id}.{format}` reference `markdown()` emits, so a figure block
 * and its image metadata can be joined without parsing markdown.
 */
final class ExtractedImage
{
    /**
     * @param  ?string  $path  Where the image was written, when `Config::$imageOutputDir` is set.
     * @param  array{x: float, y: float, width: float, height: float}  $bbox
     * @param  ?string  $duplicateOf  The `$id` of the canonical entry, when this image is a
     *                                byte-for-byte duplicate of one already seen (e.g. a repeated logo).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $page,
        public readonly array $bbox,
        public readonly int $width,
        public readonly int $height,
        public readonly float $rotation,
        public readonly string $format,
        public readonly ?string $path = null,
        public readonly ?string $duplicateOf = null,
    ) {}

    /**
     * @param  array{
     *     id: string, name: string, path?: string, page: int,
     *     bbox: array{x: float, y: float, width: float, height: float},
     *     width: int, height: int, rotation: float, format: string, duplicate_of?: string
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            page: $data['page'],
            bbox: $data['bbox'],
            width: $data['width'],
            height: $data['height'],
            rotation: $data['rotation'],
            format: $data['format'],
            path: $data['path'] ?? null,
            duplicateOf: $data['duplicate_of'] ?? null,
        );
    }
}
