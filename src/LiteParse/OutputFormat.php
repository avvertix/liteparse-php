<?php

declare(strict_types=1);

namespace LiteParse;

/**
 * Mirrors liteparse's `OutputFormat` enum, part of `Config`.
 *
 * `ParseResult::json()`, `text()`, and `markdown()` are all available
 * regardless of this setting — each renders on demand from the same parsed
 * page data. This only controls what the Rust CLI's own `--format` flag
 * would default to; the PHP binding exposes all three renderers unconditionally.
 */
enum OutputFormat: string
{
    case Json = 'json';
    case Text = 'text';
    case Markdown = 'markdown';
}
