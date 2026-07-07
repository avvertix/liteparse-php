<?php

declare(strict_types=1);

namespace LiteParse;

/**
 * Mirrors liteparse's `ImageMode` enum. Controls how raster images are
 * referenced in `ParseResult::markdown()` output. Has no effect on
 * `json()` / `text()`.
 */
enum ImageMode: string
{
    /** Strip image references entirely. */
    case Off = 'off';

    /** Emit `![](image_pN_K.png)` references in reading order, without pixel bytes. */
    case Placeholder = 'placeholder';

    /** Same references as Placeholder, plus embedded pixel bytes (not yet surfaced by this binding). */
    case Embed = 'embed';
}
