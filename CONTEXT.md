# liteparse-php

PHP FFI bindings for the Rust `liteparse` PDF-parsing crate. This context covers the
public API surface — the shapes a consumer builds their own document model from.

## Language

**Block**:
A typed value object representing one classified layout unit on a page — heading,
paragraph, list item, code, table, merged_table, grid_fallback, rule, or figure —
carrying its bounding box and kind-specific fields. Produced when `Config::$extractBlocks`
is enabled; mirrors upstream liteparse's `LayoutBlock`.
_Avoid_: layout block, chunk, element

**Structured output**:
The `Block`-based accessor path, recommended for consumers building their own
document/page model (as opposed to `markdown()`, which renders human/LLM-facing text and
is not meant to be re-parsed to recover structure — doing so loses bounding boxes and
relies on markdown syntax, like a thematic break, to stand in for page boundaries).
_Avoid_: blocks output (ambiguous with the raw array already returned by `json()`'s
`pages[].blocks`)

**Page-grouped blocks**:
`Block`s nested under each source page, matching `json()`'s existing per-page shape.
The default/familiar shape for consumers already used to LiteParse's page structure.

**Flat blocks**:
Every `Block` in the document as one reading-order list, each tagged with its own
`page_number`, with no page wrapper. An explicit, separate call from page-grouped blocks
— not the default — for consumers whose own document model has moved away from a
per-format Page concept toward a flat block stream with per-block location.
