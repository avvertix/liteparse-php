# Handoff: liteparse dependency state

Current-state snapshot for whoever (human or agent) picks up the next `liteparse` upgrade.
Read `.claude/skills/upgrade-liteparse/SKILL.md` for the upgrade *process*; read
`adaptations.md` for the full history and rationale behind every decision summarized here.
This file only says *what's true right now* — update it at the end of every upgrade,
don't let it drift into a second history log.

## Current pin

`rust/Cargo.toml`: `liteparse = "2.14.7"` (crates.io, real dependency — not a local path).
Last upgraded from `2.14.3` on 2026-09-24.

## Wired `Config.php` flags

Every `LiteParseConfig` field below has a matching `Config` constructor param + `toJson()`
entry. Anything not listed here is not exposed from PHP yet (see "Known gaps" below).

`ocrLanguage`, `ocrEnabled`, `ocrServerUrl`, `ocrServerHeaders`, `tessdataPath`,
`maxPages`, `targetPages`, `dpi`, `outputFormat`, `preserveVerySmallText`, `password`,
`quiet`, `numWorkers`, `imageMode`, `extractLinks`, `ocrFailureFatal`,
`ocrHedgeDelaysMs`, `emitWordBoxes`, `extractImages`, `imageOutputDir`,
`extractAnnotations`, `cropBox`, `skipDiagonalText`, `includeComplexity`,
`keepHeadersFooters`, `extractVectorGraphics`, `extractBlocks`,
`extractDocumentMetadata`, `extractScreenshots`, `continueOnPageError`,
`extractFormFields`, `extractStructureTree`, `extractContentBounds`,
`extractXfaPackets`, `extractTextMetadata`, `detectScreenshotRects`,
`renderFormFields`, `pageOrientationCorrections`.

Every `LiteParseConfig` field now has a matching `Config.php` constructor param, as of
the 2026-09-24 "wire everything remaining" pass — see `adaptations.md`.

`ParseResult` accessors: `pageCount()`, `json()`/`jsonString()`, `lines()`/`linesJson()`,
`text()`, `markdown()`, `search()`, `screenshots()`, `blocks()`/`flatBlocks()`, `images()`.
`json()`'s top level carries `total_pages` (always), `doc_meta` (needs
`extractDocumentMetadata`), `page_errors` (needs `continueOnPageError`), `images` (needs
`extractImages`), and each page carries `complexity`/`blocks` when their flags are set.

`blocks()`/`flatBlocks()`/`images()` (added 2026-09-24, see `adaptations.md`) are the
**recommended path for consumers building their own document model** — typed `Layout\Block`/
`Layout\TableCell`/`ExtractedImage` VOs instead of `markdown()` re-parsed through a
third-party Markdown parser, which loses bounding boxes and relies on markdown syntax
(a `-----` thematic break) standing in for page boundaries. `blocks()` groups by page
(the familiar shape); `flatBlocks()` returns one reading-order list across the whole
document, each `Block` still tagged with its own `pageNumber`. Both need
`Config::$extractBlocks`; no Rust/FFI change was needed for them — they decode the
existing `jsonString()` output in PHP. `images()` needed one Rust change (folding
`ParseResult.images` into `liteparse_result_json`'s envelope, parallel to `doc_meta`); a
figure `Block`'s `id`/`format` joins against an `ExtractedImage`'s own `id`/`format`. See
`docs/adr/0001-typed-blocks-raw-json.md` for why only this layer is typed and everything
else in `json()` stays a raw array.

## Known gaps — not wired, no open question, just not asked for yet

Everything that was a `LiteParseConfig` field is now wired (2026-09-24 pass, see
`adaptations.md`). What's left is a different shape entirely, not a field:

- `ParseSession`/`ParseBatch` (new in 2.14.3) — a batch/streaming parse API for bounded
  per-batch memory on very large documents. A different lifecycle (new handle type,
  streaming iteration), not a config flag or output field like everything wired so far —
  adopting it means designing a new PHP-facing API shape, not extending an existing one.
  Not adopted; worth it only if a future large-PDF pipeline needs it.

One documentation trap already hit and fixed while wiring the rest of this list, worth
remembering for the next one: `page.structure_tree` is **not** shaped like the
internal, doc-hidden `StructNode` (`{role, mcids, bbox, alt_text}`, used by `struct_nodes`
— a different, deliberately-unexposed field). It's `Option<StructureTree>`, a real
recursive tree (`{roots: [{type, id?, actual_text?, alt_text?, title?, attributes?,
marked_content_ids, children, annotations}, ...]}`) — confirmed by actually running the
fixture, not by reading the struct name and assuming. `grep`-ing a struct name upstream
is a hypothesis, not a fact; see step 4 of the skill.

## The one open bug to re-verify on every future bump

Table header attachment: a table's header row can still come back as a separate
`paragraph`/`heading` block sitting outside the `Table` block (`header: null` in
`markdown()`'s classifier, and outside the `table` block's `bbox` in `extract_blocks`
output) instead of inside it, when the header lands in a different xy-cut region than
the table body. Reproduces as of 2.14.7 (re-verified; unchanged since 2.14.3 — no
table-classification commit landed in the 2.14.3 → 2.14.7 range) on the committed fixture
(`tests/fixtures/pdf-headings-images-tables.pdf`, page 2's "Shape / Volume / Parameters"
table) despite three upstream commits between 2.11.1 and 2.14.3 aimed directly at this
class of bug (`de8dad8`, `804988f`, `56ccc92` — see `adaptations.md`). Each bump so far
has improved it slightly (stray leaked `rule`/`HorizontalRule` blocks around the table:
44 → 3 → 2) without fully fixing it. **Don't mark this fixed from a commit message —
re-run the fixture and look at the actual `header`/`blocks` output.**

Workaround available to PHP consumers since `extract_blocks` shipped (2.14.3): every
block carries a `bbox`, so a consumer can heuristically re-attach a detached
heading/paragraph block as the table's header by checking whether its `bbox` sits
immediately above the table's `bbox`. Not implemented in this binding; documented as an
option in `adaptations.md`.

## Two bugs the 2.14.7 bump surfaced and fixed (not upstream's fault — ours)

1. `Config::$keepHeadersFooters` was a **silent no-op for `markdown()`** since the flag
   was first wired (2.4.0 → 2.11.1 session). The old FFI code called liteparse's 3-arg
   `format_markdown(pages, outline, image_mode)` convenience wrapper, which hardcoded
   `keep_headers_footers: false` internally — the config-aware call was a *different*,
   4-arg function (`format_markdown_pages`) this binding never called. Fixed as part of
   rebuilding `liteparse_result_markdown` on liteparse 2.14.7's new `stages::*` API (the
   3-arg wrapper was removed upstream, forcing the rewrite) — `keep_headers_footers` is
   now cached on `ResultData` at parse time (same as `image_mode` already was) and
   threaded through correctly. Not independently behaviorally confirmed yet: the
   committed fixture is too small (2 pages, no repeating chrome) to show a visible diff
   either way — re-verify on a real multi-page document with running headers/footers
   before fully trusting this is visibly fixed, not just correctly wired.
2. `liteparse_result_json` used to serialize `&data.result.pages` wholesale. As of 2.14.7
   this would have started leaking liteparse-internal `ParsedPage` fields
   (`projected_lines`, `regions`, `graphics`, `figures`, `struct_nodes`, `image_refs`) into
   every `json()` response — see "Confirmed non-issues" become issues, in `adaptations.md`.
   Fixed by switching to an explicit per-page allowlist (`page_to_json` in
   `rust/src/ffi/result.rs`) instead of a wholesale struct serialize. **This changes the
   pattern in the skill's step 5** — a new `ParsedPage` field is no longer "free," it
   needs one line added to `page_to_json`.

## Where things live

- `rust/src/ffi/*.rs` — the FFI surface (`parser.rs` construct/parse, `result.rs`
  accessors — including `page_to_json`'s field allowlist and the markdown stage
  composition, `screenshot.rs` standalone screenshots, `handles.rs` opaque handle types).
- `src/LiteParse/*.php` — the PHP surface. `Config.php` mirrors `LiteParseConfig`
  field-for-field; `LiteParseFfi.php` is the raw `\FFI::cdef()` wrapper plus shared
  static helpers (`collectScreenshots`, `consumeOwnedString`, ...). `ExtractedImage.php`
  is the `images()` VO; `ScreenshotRect.php` is `Screenshot::$rects`' element VO.
- `src/LiteParse/Layout/*.php` — the typed structured-output layer (`Block`, `BlockKind`,
  `TableCell`) behind `ParseResult::blocks()`/`flatBlocks()`. See
  `docs/adr/0001-typed-blocks-raw-json.md` for why this layer is typed and `json()` stays
  raw arrays.
- `adaptations.md` — chronological research log, one section per investigation/upgrade.
  Always append; it's the record of *why*, this file is only *what's true now*.
- `CONTEXT.md` — the package's public-API glossary (Block, structured output, page-grouped
  vs. flat blocks). Domain-modeling territory, not upgrade history — update it when a
  public-API term is coined or resolved, not when a version bumps.
