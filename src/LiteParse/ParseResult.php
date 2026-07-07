<?php

declare(strict_types=1);

namespace LiteParse;

use FFI\CData;

/**
 * A parsed document. Each accessor renders on demand from the underlying
 * Rust-owned page data (`liteparse_result_*`) — call the one you need rather
 * than all of them, since markdown reconstruction in particular is not free.
 */
final class ParseResult
{
    public function __construct(
        private readonly CData $handle,
    ) {}

    public function __destruct()
    {
        LiteParseFfi::instance()->liteparse_result_free($this->handle);
    }

    public function pageCount(): int
    {
        return LiteParseFfi::instance()->liteparse_result_page_count($this->handle);
    }

    /**
     * Structured per-page text items, decoded from `jsonString()`. Each text
     * item carries its bounding box together with font size and fill/stroke
     * color (as ARGB hex, e.g. `"ff000000"`) in the same record — this is
     * `liteparse`'s full per-item `TextItem`, not the lean `{text, x, y,
     * width, height}` shape the upstream `lit` CLI's own `--format json`
     * produces. Font/color fields are only populated for native PDF text;
     * OCR-derived items (where `confidence` is present) carry `null` for
     * `font_name`/`font_size`/colors, since OCR reports no font metadata.
     *
     * @return array{pages: list<array{
     *     page_number: int, page_width: float, page_height: float, text: string, markdown?: string,
     *     text_items: list<array{
     *         text: string, x: float, y: float, width: float, height: float, rotation: float,
     *         font_name: ?string, font_size: ?float,
     *         font_height?: float, font_ascent?: float, font_descent?: float,
     *         font_weight?: int, font_flags?: int, text_width?: float,
     *         font_is_buggy?: true, has_unicode_map_error?: true, mcid?: int,
     *         fill_color?: string, stroke_color?: string, confidence?: float,
     *         link?: string, strike?: true
     *     }>
     * }>}
     */
    public function json(): array
    {
        return json_decode($this->jsonString(), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public function jsonString(): string
    {
        return LiteParseFfi::consumeOwnedString(
            LiteParseFfi::instance()->liteparse_result_json($this->handle),
            'ParseResult::json'
        );
    }

    /**
     * Structured per-page *projected lines*, decoded from `linesJson()` — a
     * middle layer between the flat `json()` (`TextItem`s: one per PDFium
     * text run, no grouping) and `markdown()` (fully reconstructed
     * headings/lists/tables, which can lose structure when a table's columns
     * land in separate layout regions — verified on a real multi-column table
     * that `markdown()` flattened into run-on paragraphs plus stray
     * `---` rules, while its lines here still carry the correct per-column
     * `region_path`). Each line is a merged visual line: bounding box,
     * dominant font/style, `region_path` (the xy-cut column/region position
     * used internally for table and paragraph grouping — same `region_path`
     * prefix means "same column/leaf"), and `spans` — the original `TextItem`s
     * that merged into this line, so per-run font/color/text survives even
     * where `text` concatenates multiple items (e.g. two cells sharing a
     * baseline). No heading/paragraph/list "role" is attached at this layer;
     * building one (e.g. from column geometry) is left to the caller.
     *
     * @return array{pages: list<array{
     *     page_number: int, page_width: float, page_height: float,
     *     projected_lines: list<array{
     *         text: string,
     *         bbox: array{x: float, y: float, width: float, height: float},
     *         anchor: "Left"|"Right"|"Center"|"Floating",
     *         indent_x: float, dominant_font_size: float, font_size_is_estimated: bool,
     *         heading_font_size: ?float, dominant_font_name: ?string,
     *         all_bold: bool, all_italic: bool, all_mono: bool, all_strike: bool,
     *         region_path: list<int>, mcid: ?int, in_figure: bool,
     *         spans: list<array{
     *             text: string, x: float, y: float, width: float, height: float, rotation: float,
     *             font_name: ?string, font_size: ?float,
     *             font_height?: float, font_ascent?: float, font_descent?: float,
     *             font_weight?: int, font_flags?: int, text_width?: float,
     *             font_is_buggy?: true, has_unicode_map_error?: true, mcid?: int,
     *             fill_color?: string, stroke_color?: string, confidence?: float,
     *             link?: string, strike?: true
     *         }>
     *     }>
     * }>}
     */
    public function lines(): array
    {
        return json_decode($this->linesJson(), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public function linesJson(): string
    {
        return LiteParseFfi::consumeOwnedString(
            LiteParseFfi::instance()->liteparse_result_lines_json($this->handle),
            'ParseResult::lines'
        );
    }

    public function text(): string
    {
        return LiteParseFfi::consumeOwnedString(
            LiteParseFfi::instance()->liteparse_result_text($this->handle),
            'ParseResult::text'
        );
    }

    /**
     * Reconstruct headings, lists, tables and figure references from the
     * spatial layout.
     */
    public function markdown(): string
    {
        return LiteParseFfi::consumeOwnedString(
            LiteParseFfi::instance()->liteparse_result_markdown($this->handle),
            'ParseResult::markdown'
        );
    }

    /**
     * Search this document's text items for phrase matches. Matches that
     * span multiple text items are merged into a single entry with a
     * combined bounding box.
     *
     * @return list<array{page_number: int, text: string, x: float, y: float, width: float, height: float}>
     */
    public function search(string $phrase, bool $caseSensitive = false): array
    {
        $ffi = LiteParseFfi::instance();
        $json = LiteParseFfi::consumeOwnedString(
            $ffi->liteparse_search($this->handle, LiteParseFfi::cstring($phrase), $caseSensitive ? 1 : 0),
            'ParseResult::search'
        );

        return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /** @internal */
    public function ffiHandle(): CData
    {
        return $this->handle;
    }
}
