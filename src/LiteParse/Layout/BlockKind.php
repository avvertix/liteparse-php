<?php

declare(strict_types=1);

namespace LiteParse\Layout;

/**
 * Discriminates `Block::$kind`. Mirrors the `kind` values `liteparse`'s
 * `LayoutBlock` emits.
 */
enum BlockKind: string
{
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case ListItem = 'list_item';
    case Code = 'code';
    case Table = 'table';
    case MergedTable = 'merged_table';
    case GridFallback = 'grid_fallback';
    case Rule = 'rule';
    case Figure = 'figure';
}
