<?php

declare(strict_types=1);

namespace LiteParse\Layout;

/**
 * One classified layout unit from a page — heading, paragraph, list item,
 * code, table, figure, or rule — as produced by `Config::$extractBlocks`.
 * Mirrors `liteparse`'s `LayoutBlock`: a single flat shape discriminated by
 * `kind`, with every field that doesn't apply to that kind left `null`.
 *
 * Reading order matches the order `ParseResult::markdown()` renders: the Nth
 * `Block` on a page is the Nth block of that page's Markdown.
 */
final class Block
{
    /**
     * @param  int  $pageNumber  The 1-based source page this block came from —
     *                           present whether the block was read via `ParseResult::blocks()`
     *                           (grouped under its page) or `ParseResult::flatBlocks()` (flattened
     *                           across the whole document), so a `Block` looks the same either way.
     * @param  ?string  $text  Rendered text for the text-bearing kinds (`heading`, `paragraph`,
     *                         `list_item`). Table text lives in `header`/`rows`; code and grid text in `lines`.
     * @param  ?int  $level  Heading level (1-6), or list nesting depth for `list_item`.
     * @param  bool  $bold  Whether the block's text is uniformly bold. `paragraph`/`list_item` only.
     * @param  bool  $italic  Whether the block's text is uniformly italic. `paragraph`/`list_item` only.
     * @param  ?bool  $ordered  `list_item`: whether the list is ordered.
     * @param  ?string  $marker  `list_item`: the original marker as it appeared on the page
     *                           (`"138."`, `"iii)"`, `"•"`).
     * @param  ?list<string>  $lines  Verbatim source lines for `code` and `grid_fallback`.
     * @param  ?string  $lang  Best-effort language hint for `code`.
     * @param  ?list<TableCell>  $header  `table`: the header row, when one was detected.
     * @param  ?list<list<TableCell>>  $rows  `table`: the body rows. `merged_table`: all rows
     *                                        (header rows lead, counted by `headerRows`); rows are ragged — cells
     *                                        covered by a neighbour's span are absent.
     * @param  ?int  $headerRows  `merged_table`: how many leading rows of `rows` are header rows.
     * @param  ?string  $id  `figure`: the image's page-scoped id, matching `ParseResult::images()`
     *                       entries and the `img_{id}.{format}` markdown target.
     * @param  ?string  $format  `figure`: the image's encoded format (e.g. `"png"`).
     * @param  ?array{x: float, y: float, width: float, height: float}  $bbox  The union of every
     *                                                                         source line that fed this block, in the page's viewport coordinates
     *                                                                         (top-left origin). Omitted for a block with no page geometry behind it.
     */
    public function __construct(
        public readonly BlockKind $kind,
        public readonly int $pageNumber,
        public readonly ?string $text = null,
        public readonly ?int $level = null,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly ?bool $ordered = null,
        public readonly ?string $marker = null,
        public readonly ?array $lines = null,
        public readonly ?string $lang = null,
        public readonly ?array $header = null,
        public readonly ?array $rows = null,
        public readonly ?int $headerRows = null,
        public readonly ?string $id = null,
        public readonly ?string $format = null,
        public readonly ?array $bbox = null,
    ) {}

    /**
     * @param  array{
     *     kind: string, text?: string, level?: int, bold?: bool, italic?: bool,
     *     ordered?: bool, marker?: string, lines?: list<string>, lang?: string,
     *     header?: list<array{text: string, bbox?: array{x: float, y: float, width: float, height: float}, colspan?: int, rowspan?: int}>,
     *     rows?: list<list<array{text: string, bbox?: array{x: float, y: float, width: float, height: float}, colspan?: int, rowspan?: int}>>,
     *     header_rows?: int, id?: string, format?: string,
     *     bbox?: array{x: float, y: float, width: float, height: float}
     * }  $data
     */
    public static function fromArray(array $data, int $pageNumber): self
    {
        return new self(
            kind: BlockKind::from($data['kind']),
            pageNumber: $pageNumber,
            text: $data['text'] ?? null,
            level: $data['level'] ?? null,
            bold: $data['bold'] ?? false,
            italic: $data['italic'] ?? false,
            ordered: $data['ordered'] ?? null,
            marker: $data['marker'] ?? null,
            lines: $data['lines'] ?? null,
            lang: $data['lang'] ?? null,
            header: isset($data['header']) ? array_map(TableCell::fromArray(...), $data['header']) : null,
            rows: isset($data['rows'])
                ? array_map(static fn (array $row) => array_map(TableCell::fromArray(...), $row), $data['rows'])
                : null,
            headerRows: $data['header_rows'] ?? null,
            id: $data['id'] ?? null,
            format: $data['format'] ?? null,
            bbox: $data['bbox'] ?? null,
        );
    }
}
