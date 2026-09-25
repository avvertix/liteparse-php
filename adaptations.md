- In json mode return all information about fonts: size and colors

TODO: Sanitize Unicode characters


Classification happens in markdown_layout::classify::classify_page_with_filters / classify_region, which consumes ParsedPage.projected_lines (merged/grouped ProjectedLines, not the raw flat text_items) and groups them into a Block enum: Heading{level,text}, Paragraph{bold,italic}, ListItem{ordered,marker,level}, CodeBlock, Table, GridFallback, HorizontalRule, Figure. That Block sequence is the "role" representation — it just gets rendered straight to a markdown string and discarded.

Heading level specifically comes from a priority chain per line (classify.rs:600-699):
1. Tagged-PDF struct tree — struct_heading_level reads the line's mcid, looks up the matching StructNode.role ("H1".."H6"), highest priority, only available on tagged PDFs.
2. Document outline/bookmarks — outline_heading_level matches a bookmark title + page/y-position against the line.
3. Font-size heading map — build_heading_map (headings.rs:644) bins font sizes strictly above the computed body size, filtered by char-count/alpha-ratio/repetition heuristics, into levels 1..N.
4. Separately, looks_like_bold_heading/looks_like_numbered_bold_heading catch body-sized bold section headers that don't clear the size threshold.

Lists, tables, code (mono font), figures (image refs), and horizontal rules (vector graphics) each have their own detector, all operating at the ProjectedLine/region level.

Can this be attached to JSON output? Two real paths, one blocker

Good news: markdown_layout is a pub mod at the crate root, and classify_page_with_filters, build_heading_map, compute_body_size, compute_header_footer_set are all pub fn. ParsedPage's internal fields (projected_lines, struct_nodes, etc.) are public struct fields — #[serde(skip)] only suppresses them from ParsedPage's own derive, it doesn't hide them from external code. So our FFI crate can already call this exact pipeline on data.result.pages, same as output::markdown::format_markdown_pages does internally, with zero changes to liteparse itself.

The blocker: Block carries no bounding box and no back-reference to which line(s)/TextItem(s) produced it — it's text + role only, and doesn't even derive Serialize yet. So calling classify_page_with_filters from our wrapper gets you a role-labeled outline (heading levels, paragraphs, list nesting, table cells...) but not tied to a bbox — unlike the font/color work, there's no TextItem-shaped object with both a role and a rect sitting there ready to serialize.

Getting role + bbox together (mirroring what we did for font/color) needs an upstream change to liteparse: add a bbox: Rect to each Block variant (trivial to compute — every construction site in classify_region already has the contributing line(s)' bboxes in scope) and derive Serialize on Block. That's a small, well-contained patch, but it's in the liteparse crate we consume as a pinned version = "2.4.0" crates.io dependency, not a local path — so it means patching upstream and cutting a new liteparse release, not something we can do from liteparse-php alone.

Recommendation: if you control the upstream liteparse repo and are open to a small patch there, that's the clean path — add bbox to Block, derive Serialize, and we expose it from our FFI layer as a blocks array alongside text_items. If you'd rather not touch upstream right now, we can still surface a role-only outline (no bbox) purely from our wrapper today, calling the existing public API as-is.

## Spike: TextItem vs ProjectedLine vs Block, on a real document

Ran a throwaway cargo example (`liteparse/crates/liteparse/examples/dump_layout.rs`, built with
`--no-default-features` to skip the tesseract build) against a real 6-page document
(`Guida Operativa Autofatture ADE.pdf`), calling the exact same public pipeline
`format_markdown_pages` uses internally (`compute_body_size` → `build_heading_map` →
`compute_header_footer_set` → `detect_single_page_chrome` → `classify_page_with_filters`), and
dumped `text_items`, `projected_lines`, and the resulting `Block` list per page to JSON
(`dump_layout.json` in scratchpad — not committed, derived from a personal file).

Counts: 250 text_items → 206 projected_lines → 151 blocks across 6 pages. Role breakdown:
20 headings, 55 paragraphs, 18 list items, 3 tables, **55 horizontal_rule**.

What each layer actually offers:

- **TextItem** (raw): one per PDFium text run. Has bbox, font, size, fill/stroke color — no
  line grouping, no role. Page 1 has 16 of these.
- **ProjectedLine** (merged, `page.projected_lines`, already `Serialize`, already a public field —
  zero code changes needed to expose today): visual lines with bbox, anchor, dominant font/size,
  all_bold/italic/mono/strike, `region_path` (xy-cut column position), `in_figure`, and `spans`
  (the original TextItems that merged into this line — so cell-level detail survives even when
  `line.text` concatenates multiple items, e.g. page 1's info-box row merged 2 TextItems — a
  label and a value — into one line with `spans_count: 2`). No role/heading-level tag exists at
  this layer yet.
- **Block** (`classify_page_with_filters` output — the actual "role" answer): heading level,
  paragraph bold/italic, list item ordered/level, table header+rows, code, HR, figure. No bbox,
  no back-reference to source lines/items, doesn't derive Serialize yet.

**Concrete finding that changes the recommendation**: page 3 contains two real 2-3 column tables
("Tipo / Quando si usa / Esempio pratico documento" and "Dato richiesto / Dove reperirlo / Nota
operativa"). Neither was detected as `Block::Table`. Because each column landed in its own xy-cut
region, the borderless-table detector (which needs the full grid in one region or a rescued
cross-region merge) missed both, so:
- every row/column cell got flattened into a single run-on `paragraph` block (e.g. the whole
  "Dato richiesto" column's 7 rows became one paragraph blob, and the "Nota operativa" column's
  cells became another single paragraph, with row alignment across columns completely lost), and
- the table's ruled gridlines got individually emitted as **44 stray `horizontal_rule` blocks**
  on this one page.

So on this document, the Block/role layer isn't just "missing bbox" — it actively loses table
structure that the raw geometry still has. `region_path` correctly separates the 3 columns
spatially (confirmed in the projected_lines dump); the classifier's per-region table synthesis is
what fails, not the grouping into lines.

**Updated recommendation**: prioritize exposing `ProjectedLine` (or, if payload size matters, a
trimmed projection: text, bbox, anchor, font/style flags, region_path, in_figure, spans) as a new
FFI accessor (e.g. `liteparse_result_lines_json`), rather than chasing `Block`+bbox upstream first.
It needs no upstream liteparse change, it's strictly richer than TextItem (adds line grouping,
region/column path, style flags) without inheriting the markdown heuristics' failure modes, and a
PHP-side consumer can do its own row/column reconstruction from `region_path` + `spans` x-positions
— which on this real document would actually recover the two tables that `Block` classification
dropped. Role/heading-level labelling can still be layered in later (either upstream, or by
re-exposing `build_heading_map`/`heading_level_for` — also already `pub` — as a separate helper),
but isn't a blocker for shipping the richer geometric layer first.

## Follow-up: is per-line role (heading level / table / figure) feasible under lines()?

Re-verified visibility with grep against the actual liteparse source rather than assuming. Correction
to the note above ("re-exposing `build_heading_map`/`heading_level_for` — also already `pub`"): that
was wrong about `heading_level_for`. Only five functions in `markdown_layout` are actually `pub` at
the crate boundary: `classify_page_with_filters`, `build_heading_map`, `compute_body_size`,
`compute_header_footer_set`, `detect_single_page_chrome`. Every granular per-line classifier —
`struct_heading_level`, `outline_heading_level`, `heading_level_for`, `heading_size_of`,
`looks_like_bold_heading`, `looks_like_numbered_bold_heading`, `is_caption_line`, `is_toc_title`,
`page_is_toc` — is `pub(super)`: visible only inside `markdown_layout`, not callable from
liteparse-php at all. `Block` still derives only `Debug, Clone` (no `Serialize`, no bbox, no
line-index back-reference).

Also confirmed: `rust/Cargo.toml` pins `liteparse = { version = "2.4.0" }` from crates.io
(`run-llama/liteparse` upstream) — this is **not** a local path dependency we can freely edit. Any
upstream change means either forking (git dependency) or landing a PR + waiting on a release.

Three real options, in order of accuracy:

1. **Fork + patch liteparse** (only path that gets *accurate* roles, including tables/figures).
   `classify_region` already tracks the contributing line index (`line_idx`) and table ranges
   (`run.start`/`run.end`) locally when it builds each `Block` — it just never returns them. Adding
   a `bbox: Rect` + resolved original-line-index range to each `Block` variant, deriving `Serialize`,
   and threading that range through the line-list transformations (chrome filtering, global
   ruled-table extraction, cross-region merge) back to the original `page.projected_lines` indices is
   a bounded, mechanical patch — but it's ongoing maintenance burden (rebase onto every upstream
   liteparse release) unless it lands upstream.
2. **Heuristic text/position matching** between `classify_page_with_filters`'s `Block` output and
   `projected_lines`, done entirely in liteparse-php, no upstream change. Rejected: paragraphs
   de-hyphenate/reflow/merge lines so text won't match verbatim, and the earlier spike already showed
   Block-level table detection silently drops whole multi-column tables on real documents — exactly
   where an accurate role label would matter most. Shipping this would look authoritative while being
   wrong in the cases that count.
3. **Standalone heading-only reimplementation** using only what's already public (`dominant_font_size`
   / `heading_font_size`, `build_heading_map`, `compute_body_size`, plus a hand-rolled struct-tree/mcid
   lookup and bold-heading check against fields already on `ProjectedLine`/`ParsedPage`). Feasible for
   "is this line a heading, and roughly what level" only — table/list role detection is far more
   stateful (multi-line consumption, cross-region merges) and not reasonably reimplementable at the
   per-line level. Even the heading case would diverge from `markdown()`'s real output since it skips
   liteparse's precision-tuned guards (TOC suppression, caption/footnote exclusion, run-in-label
   rejection, sentence-tail continuation) — expect more false-positive headings than the real
   classifier produces.

Recommendation: option 1 if the user is willing to maintain (or upstream) a liteparse patch; otherwise
don't ship a "role" field at all rather than ship options 2/3, since both would be *less* trustworthy
than the raw geometry `lines()` already exposes — a wrong role label is worse than no role label when
`region_path` + bbox already let a PHP-side consumer reconstruct structure themselves.


TODO: modify LiteParse source to expose Blocks preprocessed before they are combined in markdown 

Page -> Blocks, for each block the bounding box, the largest that covers everything inside, the role

TODO: think about triggering visual models to combine layout information

TODO: check when and where footnotes are included

## Upstream liteparse 2.4.0 → 2.11.1: what changed and what it means for us

Cloned upstream at `../liteparse` (now on tag `crates-v2.11.1`; our `rust/Cargo.toml` still pins
`2.4.0`). Diffed `crates-v2.4.1..crates-v2.11.1` for the six areas raised.

1. **Document provenance metadata** (`document_metadata.rs`, PR #381). New `ParseResult.doc_meta:
   Option<DocumentMetadata>` — creation/mod dates, PDF version, encryption + security-handler
   revision + permissions bitmask, signature count, incremental-save forensics
   (`eof_section_count`, `startxref_count`, `trailer_id_pair_differs` — signs of a resaved/edited
   file), raw XMP packet, raw file size. Opt-in via new config flag `extract_document_metadata`
   (`None` for non-PDF inputs converted through LibreOffice/image path). Useful for provenance/
   tamper-evidence checks on ingested documents. **Gap**: lives on `ParseResult`, not
   `ParsedPage`, so it isn't reached by any existing FFI accessor (`liteparse_result_json` only
   serializes `data.result.pages`) — needs a new accessor or folding into the JSON envelope.

2. **`keep_headers_footers` config option** (PR, Logan). Markdown-only: by default repeated
   top/bottom-band chrome (running headers, `Page N of M`) is stripped from `markdown()`; this
   flag retains it. Also tightened the header/footer *detection* itself (two-column tables inside
   a booktabs rule band were previously getting misread as repeating chrome).

3. **`isComplex` / layout complexity signals**, landed in two steps: page-level OCR-need signals
   became inline-optional (`include_complexity` config flag → `ParsedPage.complexity:
   Option<PageComplexityStats>` — text coverage, image coverage, garbled-text detection, OCR
   reasons), then extended with a nested `layout: Option<LayoutComplexityStats>` (column count,
   ruled/borderless table counts + coverage, figure count + coverage, annotation-driven signals).
   This is a page-level triage signal — e.g. skip the OCR/vision fallback on simple pages, force it
   on high `column_count`/table pages. Both flags default `false` (the walk isn't free).

4. **Pure-Rust image conversion, ImageMagick dropped** (PR run-llama/liteparse#348 area, several
   commits from `01b6ff0`). Internal — no API shape change — but it removes a host dependency.
   `src/LiteParse/LiteParse.php:41`'s docblock on `parseFile()` still says non-PDF conversion
   "requires LibreOffice and/or ImageMagick to be installed on the host" — that's now stale for the
   image-conversion half (LibreOffice is presumably still needed for DOCX/XLSX/PPTX → PDF; only the
   ImageMagick leg was replaced). Worth fixing once we bump the pin.

5. **Word bboxes fix** (`8823426`, `projection.rs` only). Not new API — `WordBox`/`emit_word_boxes`
   already existed at 2.4.0 — just a correctness fix to how per-word boxes are computed during
   projection. Free improvement once we bump, no wiring needed.

6. **Table detection was substantially reworked** — not one of the six areas named, but the
   biggest find here given our earlier spike (`Guida Operativa Autofatture ADE.pdf`, page 3) showed
   `Block::Table` silently missing two real multi-column tables because each column landed in its
   own xy-cut region, dumping 44 stray `horizontal_rule` blocks instead. 11 commits since 2.4.1
   touch `markdown_layout/tables.rs`: booktabs-enclosed two-column table detection, rowspan-from-
   rules reading, splitting PDFium merged runs on real word geometry, recovering unruled outer
   rows/columns on ruled tables, soft-wrap row grouping. **This looks like it may directly fix the
   cross-region-table blind spot** that was central to the "don't trust `Block` for tables" verdict
   in the section above — worth re-running the `dump_layout` spike against the same document on
   2.11.1 before finalizing that recommendation.

### Update: version bumped, spike re-run on 2.11.1

Bumped `rust/Cargo.toml` to `liteparse = "2.11.1"`; `cargo build --release` for `liteparse-php`
completed clean (10m18s, no compile errors — the six areas above are additive, not breaking).

Re-ran the `dump_layout` spike, this time against the committed fixture
`tests/fixtures/pdf-headings-images-tables.pdf` (the original real-world doc from the first spike
was a personal file, not appropriate to reuse here). One compile fix was needed: `Block::Figure`
gained a `format: String` field in this range (small nice-to-have — figure blocks now know their
image format), so the example's match arm needed updating.

Result on the fixture's one ruled table (page 2, a 2-column "Shape / Volume / Parameters" table
with math-formula cells): `role_counts` shows `table: 1` (correctly classified, not lost as a
paragraph blob) — better than the catastrophic full-miss seen on the original 3-column real
document. But the **same root cause is still live**: the header row ("Shape Volume Parameters")
landed in `region_path: 0` while every table body line landed in `region_path: 1` — a different
xy-cut region — so the `Table` block's `header` came back `None` even though the header text was
right there in `projected_lines`. Three separate `HorizontalRule` blocks (the table's top border,
header-separator rule, and bottom border) also got emitted standalone around the table rather than
consumed into it, echoing the "gridlines leak out as stray HR blocks" symptom, just at fixture
scale (3, not 44).

**Verdict: not fixed, improved.** Body-row capture into a real `Table` block is more robust now,
but cross-region header attachment — the specific failure mode that made `Block` untrustworthy for
table roles — still reproduces on our own fixture. The original recommendation stands: don't build
a "role" field on `Block::Table` output; `lines()` + `region_path` remains the safer foundation for
a PHP-side consumer to reconstruct table structure itself.

(Also observed, unrelated to tables: the math-formula cells extract with correct Unicode — `ℎ`,
`√` — but PDF fraction/sqrt typesetting has no semantic markup, so stacked numerator/denominator
glyphs read out in a jumbled flat order, e.g. `2 - 2 ℎ 4` for what's visually a fraction. Expected
PDF-extraction limitation, not a liteparse regression — flagging only so it isn't mistaken for a
table-detection bug on re-read.)

**What adopting this needs, concretely:**
- Bump `rust/Cargo.toml` `liteparse` pin `2.4.0` → `2.11.1` (breaking-change check: none of the
  above look breaking, but should diff `config.rs`/`types.rs` defaults fully before bumping, not
  just the six areas asked about).
- `Config.php` builds `toJson()` from a fixed field allowlist (`src/LiteParse/Config.php:58-80`) —
  new Rust config flags are invisible to PHP callers until added there explicitly (`serde(default)`
  on the Rust side means omission is silently "off", not an error). None of `keep_headers_footers`,
  `include_complexity`, `extract_document_metadata` are wired yet.
- `doc_meta` needs a new FFI accessor (or addition to `liteparse_result_json`'s envelope) since
  `ParseResult` fields outside `pages` currently aren't reachable at all.
- `complexity` on `ParsedPage` and `vector_graphics`/`content_bounds` (also new-ish public fields)
  flow through **for free** once the flags are set — `liteparse_result_json` already serializes
  whole `ParsedPage` structs, so no Rust-side code change needed for those, only the Config.php
  allowlist entries and the version bump.

(Session update: `includeComplexity`/`keepHeadersFooters` wired into `Config.php`, along with the
five other fields that had become mandatory upstream with no serde default — `extractImages`,
`imageOutputDir`, `extractAnnotations`, `cropBox`, `skipDiagonalText`, `extractVectorGraphics` —
which were silently breaking `new Config()` on 2.11.1 before this fix. `doc_meta` FFI accessor was
not done. Verified via `composer test`/`composer lint`, both clean.)

## Upstream liteparse 2.11.1 → 2.14.3: prepping the next upgrade

User cloned upstream `main` (now at `2.14.3`, tag `crates-v2.14.3`) into `../liteparse` and had
already bumped `rust/Cargo.toml`'s pin themselves before asking for a compatibility check. 77
commits touch `crates/liteparse/src` since `crates-v2.11.1`, heavy on one-word "wip" commits from a
long block-layout branch — the real signal is in the named `feat:`/`fix:` commits.

**Build result: clean.** `cargo build --release` for `liteparse-php` against `2.14.3` compiled with
zero errors (9m24s). `composer test` (20/20) and would need re-running after any Config.php changes
— ran clean against the rebuilt library with the Config.php from the 2.11.1 session already in
place, no source changes needed to keep compiling. Every new `LiteParseConfig` field added since
2.11.1 carries a proper `#[serde(default = ...)]` (a discipline that wasn't consistently followed
between 2.4.0 and 2.11.1, where several new mandatory fields broke JSON config parsing) — so the
existing Config.php's JSON payload keeps working untouched; nothing is *forced* to change this time.

**The big one — `extract_blocks` (PR "add block-level layout to output", `3a1aa39`).** This is
exactly the upstream patch this project's earlier "can we get role classification on `lines()`"
research (see above) concluded would be needed and recommended pursuing only if upstream would
accept it: `Block` (heading/paragraph/table/figure/etc.) now gets a public, serializable, flat
`LayoutBlock` shape with a `bbox: Option<Rect>` on every block — the union of every source line that
fed it. New opt-in config flag `extract_blocks: bool` (proper `#[serde(default)]`) → new
`ParsedPage.blocks: Option<Vec<LayoutBlock>>`, flows through `liteparse_result_json` for free once
the flag is added to Config.php, exactly like `complexity` did. Table cells (`LayoutCell`) carry
their own optional bbox too, plus `colspan`/`rowspan` on a new `merged_table` kind that fuses
multi-row-header/spanning tables the plain `table` kind can't represent.

Verified directly against the FFI (raw JSON config, `extract_blocks: true`) on the same
`tests/fixtures/pdf-headings-images-tables.pdf` table used in the 2.11.1 test:
- Every block, including every table cell, now carries a real `bbox` — the exact "role + bbox"
  pairing the earlier research said was blocked on an upstream patch. This landed for real, no fork
  needed.
- **The header-attachment bug survives, improved.** `de8dad8` ("try to properly absorb table
  headers"), `804988f` ("improve sparse markdown table detection"), and `56ccc92` ("keep multi-line
  table headers within the same x pos") are direct fixes aimed at this exact class of bug. Result on
  our fixture: the header row ("Shape Volume Parameters") still comes back as a separate `paragraph`
  block outside the table (`header` is still `null`), but stray rule-line leakage dropped from 3 to
  2 `rule` blocks flanking the table. Better, not fixed.
- **Updated recommendation**: given `bbox` is now available on every block for free, the
  cost/benefit shifts — a PHP-side consumer can now sanity-check a `Table` block's `header: null`
  case by looking at whether an unattached `heading`/`paragraph` block's `bbox` sits immediately
  above the table's `bbox` and looks like a plausible header row, entirely client-side, no fork
  needed. This is worth exposing now; it wasn't worth building a role field around before.

**Other new-but-safe additions worth knowing about** (all opt-in, all proper `serde(default)`,
none forced):
- `extract_screenshots` (`bcd8d77`) → new `ParseResult.screenshots: Vec<ScreenshotResult>` — parse
  can now return page PNGs directly instead of requiring a separate screenshot call.
- `continue_on_page_error` (`5dd6339`, hardened in `80f62c7`) → new `ParseResult.page_errors:
  Vec<PageError>` (`{page, message}`) — lets a multi-page-error PDF still return partial results
  instead of failing the whole parse.
- `total_pages: u32` unconditionally added to `ParseResult` (`e46b369`) — source page count before
  `max_pages`/`target_pages` truncation. Not gated by any config flag.
- Table-detection kept improving beyond the header fix: `82f3657` gives `SpanCell` an optional bbox
  symmetric with plain `Cell`; `539f656` improves garbled-text detection for mixed fonts.
- A new `ParseSession`/`ParseBatch` public API (`2365679`, `7d3f93a`) for batch/streaming
  large-document parsing — pure addition, not something our binding currently needs, but worth
  knowing about if a future large-PDF pipeline wants bounded per-batch memory instead of one big
  parse.

**Confirmed non-issues:**
- "Remove native office parsing" (`8d3f911`) sounds alarming but only removes an experimental
  Rust-native DOCX/PPTX/XLSX parser (relocated to a closed platform repo) that this binding never
  used. The LibreOffice shell-out conversion path `LiteParse.php:41` documents and that
  `parseFile()` actually relies on for non-PDF input is untouched.
- `LiteParseError` gained `#[non_exhaustive]` and (transiently, since reverted) a `MemoryBudget`
  variant — irrelevant since `rust/src/ffi/*.rs` never matches on `LiteParseError`, only calls
  `.to_string()` on it.
- `Library::init`/`try_init` changes are internal to liteparse; our FFI layer never calls `Library`
  directly.
- A `memory_budget_mb`/`ocr_raster_budget_mb` pair landed in `1c6b9ef` then appears to have been
  dropped again before `2.14.3` — confirmed absent from the current `config.rs`, so nothing to wire
  and nothing that would have broken us either way (it shipped with a correct
  `#[serde(default = "fn")]`, not a bare default, so it wouldn't have silently zeroed the budget).

**What adopting 2.14.3 needs, concretely:**
- The version bump itself: done (by the user, pre-session), builds clean, tests pass, nothing
  forced.
- `total_pages`, `screenshots`, `page_errors`, and `doc_meta` (carried over from the 2.11.1 gap) are
  now four different pieces of `ParseResult`-level data stranded with no FFI accessor —
  `liteparse_result_json` only ever serialized `data.result.pages`. Worth fixing these together as
  one FFI change (e.g. a `liteparse_result_meta_json` accessor, or folding them into the existing
  JSON envelope alongside `"pages"`) rather than bolting on four one-off functions.
- `extract_blocks` is the standout feature to wire into `Config.php` next given it's a direct,
  better-than-planned answer to this project's earlier role-classification research.

### Update: both done — `extract_blocks` wired, accessor gap closed

Chose the JSON-envelope approach over a separate accessor for `total_pages`/`doc_meta`/`page_errors`:
`liteparse_result_json` (`rust/src/ffi/result.rs`) now emits them as top-level siblings of `"pages"`
(`total_pages` always present, `doc_meta` null and `page_errors` `[]` unless their config flags are
on). `screenshots` couldn't join that envelope — PNG bytes would need base64 — so it got its own
accessor instead: `liteparse_result_screenshots` returns a `ScreenshotListHandle`, reusing the exact
handle type and PHP-side accessors (`liteparse_screenshot_list_len`/`_bytes`/...) the standalone
`liteparse_parser_screenshot_file`/`_bytes` calls already used. The PHP-side collection loop
(`CData` list → `Screenshot[]`) was previously private to `LiteParse`; pulled it out to
`LiteParseFfi::collectScreenshots()` so `ParseResult::screenshots()` could reuse it too instead of
duplicating the loop.

`Config.php` gained four flags: `extractBlocks`, `extractDocumentMetadata` (was already available
in liteparse but never wired, per the 2.11.1 gap above), `extractScreenshots`, `continueOnPageError`.

Verified end-to-end through the real PHP API (not just raw FFI JSON) on
`tests/fixtures/pdf-headings-images-tables.pdf`, all four flags on at once: `total_pages` correct,
`doc_meta` populated (13 fields, including an `xmp_truncated` field added to `DocumentMetadata`
sometime after the 2.11.1 research — docblock updated to match), `page_errors` empty (no errors on
this fixture), per-page `blocks` present with the same shape confirmed in the 2.14.3 prep pass, and
`screenshots()` returning two real PNGs (701145 / 514833 bytes) end to end through the new accessor.
Re-checked with a plain `new Config()`: `doc_meta` null, `page_errors` [], no `blocks` key on any
page, `screenshots()` returns `[]` — all four features fully inert by default, as intended.
`composer test` (20/20) and `composer lint` both clean throughout.

README's config table and `ParseResult::json()`'s return-type docblock were updated to match: the
docblock also picked up `blocks`' full shape (`LayoutBlock`/`LayoutCell`, `bbox`/`colspan`/`rowspan`)
and the `doc_meta`/`page_errors`/`total_pages` envelope fields. While in the README table, also
back-filled six rows from the 2.11.1 session's `Config.php` additions that were wired but never
documented there (`extractImages`, `imageOutputDir`, `extractAnnotations`, `cropBox`,
`skipDiagonalText`, `extractVectorGraphics`).

This session also created `.claude/skills/upgrade-liteparse/SKILL.md` (the repeatable process
distilled from the two upgrades above) and `HANDOFF.md` (a concise, updated-every-time current-state
snapshot — pin, wired flags, known gaps, the one open bug — distinct from this file's chronological
log). Both are referenced from here on rather than restated.

## Upstream liteparse 2.14.3 → 2.14.7 (2026-09-24)

Small bump — 14 commits touching `crates/liteparse/src` (`git log --oneline
crates-v2.14.3..crates-v2.14.7 -- crates/liteparse/src`), most either `nit`/cleanup or internal to a
new `raw_text`/rotation-handling feature. One config addition: `page_orientation_corrections:
Vec<PageOrientationCorrection>` (`52f8bff`), proper `#[serde(default)]`, not wired (see `HANDOFF.md`
"Known gaps") — niche, needs a caller-side orientation classifier to be useful. `ParsedPage` also
gained an always-present `page_label: Option<String>` (from the PDF's `/PageLabels`, `e00ac42`) —
free, no config gate, will show up in `json()` automatically whenever a PDF defines one.

**Housekeeping note**: while scoping this diff, found that `5944de2` ("raw text items and fallible
library init") — analyzed in the 2.11.1 → 2.14.3 section above as if it landed in that range — is
actually *not* an ancestor of `crates-v2.14.3` (`git merge-base --is-ancestor 5944de2 crates-v2.14.3`
returns false). It's genuinely new in this 2.14.3 → 2.14.7 range instead. Likely cause: the sibling
`../liteparse` checkout was probably sitting past the `crates-v2.14.3` tag (on `main`, ahead of it)
during that pass, and the tag-range diff command's output got mixed up with manual exploration. Net
effect is harmless either way — the commit is purely additive (`extract_raw_text_items`,
`Library::try_init`, neither used by this binding) — but flagging the mis-attribution so it isn't
trusted as "already checked at the right version" again. Lesson folded into the skill isn't needed
here; the existing "build first, don't hand-verify tag ranges" step already would have caught any
real consequence.

**cargo build --release failed** on the first attempt — a real, compiler-caught breaking change:
`liteparse::output::markdown` module no longer exists. `3bc9bca` ("Expose the parse pipeline as
public stage functions") replaced the old single-call `output::markdown::format_markdown(pages,
outline, image_mode) -> String` with composable functions in a new `stages` module
(`document_signals` → `extract_blocks` → `render_page_markdown`, joined by the caller). `parser.rs`
internally now only runs this pipeline when `output_format == Markdown`, so there's no longer a
"just call this on any already-parsed pages" convenience function — a binding that wants markdown
available regardless of how the document was originally configured (this one always has: `outputFormat`
is documented as "informational only... text()/markdown()/json() are always available regardless")
has to recompose the stages itself.

Rewrote `liteparse_result_markdown` (`rust/src/ffi/result.rs`) on `stages::document_signals` +
`stages::BlockOptions` + `stages::extract_blocks` + `stages::render_page_markdown`, joined with the
same `"\n\n-----\n\n"` separator the old function used. This is a straight recomposition, not a
behavior change — verified byte-for-byte: markdown length on `tests/fixtures/pdf-headings-images-tables.pdf`
was `1447` both before (2.14.3 test, same fixture) and after this rewrite.

**Real bug #1, found while rewriting**: `Config::$keepHeadersFooters` has been a **silent no-op for
`markdown()` since it was first wired**, in the 2.4.0 → 2.11.1 session. The old FFI code called the
3-arg `format_markdown(pages, outline, image_mode)` — checked its 2.14.3 source directly: it
hardcodes `format_markdown_pages(pages, outline, image_mode, false)` internally, `false` being
`keep_headers_footers`. The config-aware variant was a *different*, 4-arg function
(`format_markdown_pages`, exported separately) that this binding's FFI layer never called. The flag
reached `LiteParseConfig` and round-tripped through JSON correctly; it just never influenced
`markdown()`'s actual rendering. Fixed as a side effect of the forced rewrite above:
`ResultData` (`rust/src/ffi/handles.rs`) now caches `keep_headers_footers` from the parser's config
at parse time (same reason `image_mode` already was cached there), and both are passed into
`BlockOptions`. **Not independently behaviorally re-verified** — the committed fixture is 2 pages
with no repeating header/footer content, so there's nothing for the flag to visibly suppress either
way on it; the fix is confirmed correct by code inspection and successful compilation with the right
types threaded through, not by observing a changed output. Verify on a real multi-page document with
running chrome before trusting this is *visibly* fixed rather than just correctly wired.

**Real bug #2, found while rewriting `liteparse_result_json` for the same reason**: `ParsedPage`'s
internal-only fields (`projected_lines`, `regions`, `graphics`, `figures`, `struct_nodes`,
`image_refs`) had unconditional `#[serde(skip)]` at 2.14.3; confirmed via `git show
crates-v2.14.3:crates/liteparse/src/types.rs`. As of 2.14.7 (part of the same `3bc9bca` restructuring,
described in its own commit message as "internal fields no longer skipped; public JSON output is
unchanged since it uses its own view structs") most of these switched to `#[serde(default,
skip_serializing_if = "Vec::is_empty")]` — genuinely serializable now, just conditionally. Upstream's
own claim ("public JSON output is unchanged") is true for *upstream's* JSON output
(`output/json.rs`'s `JsonPage`, used by every other binding and the `lit` CLI, which builds its own
field list and was never affected) — but this binding's `liteparse_result_json` deliberately
bypasses that lean view and serializes `&data.result.pages` wholesale specifically to get the richer
`TextItem` fields (font size, color, ...) the lean view drops. That choice is exactly what exposed us
here: naively rebuilding against 2.14.7 would have started leaking six previously-invisible internal
fields into every `json()` response, unannounced, with shapes that were never meant to be public and
aren't documented anywhere upstream.

Fixed by replacing the wholesale serialize with an explicit per-page allowlist function
(`page_to_json` in `rust/src/ffi/result.rs`): only `page_number`, `page_label`, `page_width`,
`page_height`, `content_bounds`, `text`, `markdown`, `text_items`, `vector_graphics`, `complexity`,
`annotations`, `form_fields`, `structure_tree`, `blocks` are emitted — the same set already
documented in `ParseResult::json()`'s PHP docblock, and each Option field is omitted (not emitted as
`null`) when absent, matching the pre-upgrade shape exactly. Verified: default `Config` parse of the
fixture shows no `projected_lines`/`regions`/`graphics`/`figures`/`struct_nodes`/`image_refs` key
anywhere in `json()`'s page objects; `page_to_json`'s allowlist itself is now the enforcement point,
so a *future* "internal field no longer skipped" change upstream is inert here unless someone
deliberately adds a line for it. Also fixed a stale, mildly wrong doc comment on
`liteparse_result_lines_json` that had reasoned its way to the right conclusion via an incorrect
description of what `#[serde(skip)]` actually suppresses (it isn't scoped to "ParsedPage's own
derive" as the old comment claimed — there's only one derived `Serialize` impl per struct, period;
`lines_json` was always safe because it serializes `&page.projected_lines` as its own standalone
value, not because of anything about *which* derive was calling it).

Both fixes verified together: `cargo build --release` clean (no warnings), `composer test` 20/20,
`composer lint` clean, and a dedicated smoke test hitting the real PHP API (not raw FFI) confirming:
no internal-field leakage in `json()`, `markdown()` unchanged (length 1447, `-----` separator
present), `extractBlocks` output unchanged (`table`/`rule`/`heading`/... sequence identical to the
2.14.3 pass — the still-open header-attachment bug reproduces identically, confirming this bump
didn't touch table classification).

The skill (`SKILL.md`) itself was updated: step 2 now calls out both incident classes explicitly
(removed/restructured functions this binding calls directly; weakening `#[serde(skip)]` on a type
serialized wholesale) as things a clean build does not rule out, and step 5's "free, via full-struct
serialize" pattern was rewritten to "allowlisted page field," since wholesale serialization is
exactly what caused bug #2 — the old pattern description was actively wrong advice for a future
upgrade to follow.

## API-surface audit and `Layout\Block`/`images()` (2026-09-24)

Asked "what new fields were added [since the very first version pin], which matter for
benchmarking" as a follow-up to the 2.14.7 upgrade. Answering it properly required re-walking
`ParseResult`/`LiteParseConfig`/`ParsedPage` in the sibling checkout field-by-field against what
`Config.php`/`ParseResult.php`/`rust/src/ffi/result.rs` actually wire, rather than trusting the
"Known gaps" list `HANDOFF.md` already had — which turned out to be itself incomplete. Confirmed
sibling checkout HEAD (`b754dc3`, `wasm-v2.14.7`/`node-v2.14.7` tags) has zero commits touching
`crates/liteparse/src` since `crates-v2.14.7` (`git log --oneline crates-v2.14.7..HEAD --
crates/liteparse/src` → empty), so diffing against it is equivalent to diffing against the actual
pin.

Found five real gaps beyond `HANDOFF.md`'s existing list, three of them present since
`crates-v2.14.3` and missed by that upgrade's own audit (confirmed via
`git show crates-v2.14.3:crates/liteparse/src/config.rs`):

1. **`ParseResult.images` had zero accessor.** `extractImages`/`imageOutputDir` were wired as
   *config inputs* since the very first session, but the resulting `ExtractedImage` metadata
   (`id`, `name`, `path`, `page`, `bbox`, `width`, `height`, `rotation`, `format`, `duplicate_of` —
   everything except `bytes`, which upstream marks `#[serde(skip)]` on purpose, "an image payload
   crossing a boundary goes as a file or a separate blob, keyed by `id`") was never returned. A
   caller turning on `extractImages` got files on disk with no manifest of what was written where.
2. **`extractContentBounds` unwired, and `page_to_json`'s existing `content_bounds` allowlist
   branch was dead code.** Upstream computes `content_bounds` internally regardless (needed for the
   white-fill heuristic under `extract_vector_graphics`), then explicitly zeroes it back to `None`
   unless `extract_content_bounds` is `true` (`parser.rs`: `if !self.config.extract_content_bounds
   { page.content_bounds = None; }`). Present at `crates-v2.14.3` already.
3. **`extractXfaPackets` → `ParseResult.xfa_packets`** entirely missing from both the binding and
   `HANDOFF.md`'s gap list. Present at `crates-v2.14.3` already.
4. **`creator`/`producer`** — top-level `ParseResult` fields (the PDF `/Info` dict's
   Creator/Producer), confirmed *not* part of `DocumentMetadata` (checked field-for-field, 13
   fields, no overlap) despite reading like they belong there.
5. **`ScreenshotResult.is_solid_fill`/`rects`** dropped by both the standalone
   `liteparse_parser_screenshot_*` calls and the newer `liteparse_result_screenshots` — confirmed
   via `grep` across `rust/src`, zero matches for either field. `rects` (gated by
   `detect_screenshot_rects`) never had an accessor path at all, unlike the others here.

Ran a `/grilling` session (via the `mattpocock-skills` plugin's `grill-with-docs` → `grilling` +
`domain-modeling` skills) to decide how to wire these into the public API, anchored on a concrete
consumer which today calls `parseFile()->markdown()` and re-parses the Markdown through CommonMark
to rebuild its own `Page`/`Block` tree. Full transcript of the decisions is in the conversation;
summary of what shipped:

- **Scope**: `liteparse-php` only. The `onlytext-cloud` rewrite to actually consume the new API is
  a deliberate follow-up, not part of this pass.
- **`ParseResult::blocks(): array<array{page_number: int, blocks: Block[]}>`** and
  **`ParseResult::flatBlocks(): Block[]`** — the recommended path for structure-aware consumers,
  positioned in the README ahead of the markdown-re-parse pattern. `blocks()` groups by page
  (the shape existing `json()['pages'][]['blocks']` users already expect); `flatBlocks()` returns
  one reading-order list per document, every `Block` still carrying its own `$pageNumber` — added
  because Parxy's own `Page.php` docblock says the team is deliberately moving *away* from
  Page-wrapped blocks toward a flat block stream with per-block `Location`, so the flat shape
  should already exist rather than being something every consumer re-derives. Both are decided as
  an explicit *second* method, not a flag on `blocks()` — page-grouped stays the default so
  existing-pattern users aren't surprised. Both are pure PHP on top of the existing
  `jsonString()`/`extractBlocks` output; no Rust change needed.
- **`Layout\Block`** — one flat class discriminated by a `BlockKind` backed enum (consistent with
  `OutputFormat`/`ImageMode`'s existing pattern in `Config.php`), mirroring upstream's own
  `LayoutBlock` shape rather than a subclass-per-kind hierarchy — deliberately *not*
  over-engineered per direct instruction mid-session. `bbox` stays a raw
  `array{x,y,width,height}` on `Block` rather than a wrapper VO, for the same reason, and because
  this package has no business picking a bbox convention on a downstream consumer's behalf (Parxy's
  own `BoundingBox` is `{x0,y0,x1,y1}`, bottom-left origin — a different shape entirely; conversion
  is the consumer's job).
- **`ParseResult::images(): ExtractedImage[]`** — kept separate from `Block` rather than merged
  onto `figure` blocks (same "don't bloat `Block` with fields only one kind uses" reasoning), joined
  by the `id`/`format` a figure block already carries. This one needed a real Rust change: folded
  `"images": &data.result.images` into `liteparse_result_json`'s envelope, same pattern as
  `doc_meta`/`page_errors`. No new config flag — `extractImages` already gated it upstream.
- **Parked deliberately**: `xfa_packets`, `extractContentBounds`, `creator`/`producer`, and
  screenshot `rects`/`is_solid_fill` — real gaps, fully documented in `HANDOFF.md` with exact
  upstream shapes and gating, but with no concrete consumer driving their shape yet ("we only have
  one clear use case" — the same over-engineering concern that shaped `Block`/`bbox` above, applied
  to the whole gap list rather than just one class).
- **`docs/adr/0001-typed-blocks-raw-json.md`** — records *why* only the blocks layer got typed VOs
  and `json()`/`lines()`/`search()` stayed raw arrays (a full VO layer over `json()` would mean
  maintaining a parallel class hierarchy in lockstep with every upstream bump, for fields most
  consumers only read a handful of; the blocks layer is the one place that cost is worth paying,
  confirmed by the Parxy case doing exactly that mapping by hand already).
- **`CONTEXT.md`** created (first use in this repo) — defines `Block`, `structured output`,
  `page-grouped blocks`, `flat blocks` as the package's public-API vocabulary.

Verified: `cargo build --release` clean, `composer test` 25/25 (5 new tests in
`tests/Integration/LayoutTest.php`), `composer lint` clean, plus a scratch smoke test against
`tests/fixtures/pdf-headings-images-tables.pdf` confirming `flatBlocks()`'s page-number tagging
matches `blocks()`'s grouping exactly, and that `images()` correctly reports the fixture's second
image as `duplicateOf` the first (same embedded logo referenced from both pages) rather than a
distinct entry — upstream dedup behavior, not a binding bug.

## Wiring every remaining `LiteParseConfig` field (2026-09-24)

Immediate follow-up to the blocks/images pass: "take all additional fields and capabilities and
include them." Scope was every item still in `HANDOFF.md`'s "Known gaps" list except
`ParseSession`/`ParseBatch` (a different API shape — a batch/streaming parser lifecycle, not a
config flag or output field like the rest of the list — called out separately rather than folded
in silently). Concretely: `extractFormFields`, `extractStructureTree`, `extractContentBounds`,
`extractXfaPackets`, `extractTextMetadata`, `detectScreenshotRects`, `renderFormFields`,
`pageOrientationCorrections`, plus the already-scoped `xfa_packets`/`creator`/`producer`/
`content_bounds`/screenshot `rects`/`is_solid_fill` fields those flags unlock.

Wiring pattern per field, all following patterns already established by the skill:

- **`extractFormFields`/`extractStructureTree`/`extractContentBounds` needed zero Rust changes.**
  `page_to_json`'s allowlist already had `form_fields`/`structure_tree`/`content_bounds` branches
  from earlier sessions — they were simply unreachable because no config flag ever set the
  upstream field to non-`None`. Wiring the three `Config.php` flags alone made all three live.
- **`extractXfaPackets` and `creator`/`producer`** — new envelope fields in `liteparse_result_json`,
  same pattern as `doc_meta`/`images`. `creator`/`producer` need no config flag at all — they're
  unconditional top-level `ParseResult` fields (the PDF `/Info` dict's entries), present whenever
  the source document has them.
- **`extractTextMetadata`** needed zero Rust changes for a different reason than the first group:
  `page_to_json` already serializes `&page.text_items` wholesale (`TextItem`'s own `Serialize`
  derive, not an allowlist — unlike `ParsedPage` itself), so `char_codes`/`trailing_space_generated`
  reach PHP automatically the moment upstream populates them. Only `Config.php`'s flag and the
  `ParseResult::json()` docblock needed updating. Same reasoning retroactively explained why
  `words` (`Config::$emitWordBoxes`) was already reaching PHP correctly despite never being
  documented — fixed that docblock gap in the same pass.
- **`detectScreenshotRects`/`is_solid_fill`** needed real new Rust: two accessors,
  `liteparse_screenshot_is_solid_fill` (returns `bool` directly — confirmed cbindgen already
  emits `#include <stdbool.h>` in the generated header despite no prior function using it) and
  `liteparse_screenshot_rects_json` (a JSON-string accessor, matching the existing small-struct
  pattern rather than building a second nested handle type for a handful of rects per screenshot).
  `Screenshot.php` gained `isSolidFill`/`rects` properties and a new `ScreenshotRect` VO;
  `is_solid_fill` needed no config flag (always computed upstream), `rects` needs
  `detectScreenshotRects`.
- **`renderFormFields`/`pageOrientationCorrections`** are pure config inputs with no new output
  shape — they change existing raster bytes / text-item coordinates, not what gets returned.
  Config.php flags only.

One real mistake caught by testing rather than assumed correct from reading: the original plan
(and first docblock draft) assumed `page.structure_tree` was shaped like the internal, doc-hidden
`StructNode` type (`{role, mcids, bbox, alt_text}`) found by grepping `types.rs` for a
plausible-sounding struct name. Running the fixture with `extractStructureTree: true` produced a
completely different, recursive shape — `StructNode` backs the *different*, deliberately-internal
`struct_nodes` field; `structure_tree` is actually `Option<StructureTree>`
(`{roots: [{type, id?, actual_text?, alt_text?, title?, attributes?, marked_content_ids, children,
annotations}, ...]}`, `children` recursing). Caught immediately because `ExtendedFieldsTest`
asserted against real output rather than the assumed shape, failed, and the actual JSON was
inspected directly (`var_export`) to correct it — the exact discipline the skill's step 4 already
calls out for markdown/table classification, evidently generalizes to *any* field whose shape
wasn't independently confirmed by running the parser.

Verified: `cargo build --release` clean, new symbols confirmed present in the regenerated
`include/liteparse_php.h`, `composer test` 34/34 (9 new tests in
`tests/Integration/ExtendedFieldsTest.php`), `composer lint` clean, plus a scratch smoke test
exercising every new flag together against the fixture: `content_bounds` populated with real
coordinates, `form_fields`/`structure_tree` present as page keys (structure tree non-trivial —
the fixture is a tagged PDF), `char_codes`/`trailing_space_generated` gated correctly (present only
with the flag on, `trailing_space_generated` correctly omitted rather than `false` per its
`skip_serializing_if`), `xfa_packets` correctly `null` off / `[]` on for this non-XFA fixture,
`creator`/`producer` present unconditionally (`"Typst 0.14.2"` / `null` for this fixture), and
`is_solid_fill`/`rects` populated identically through both `screenshots()` and the standalone
`screenshotFile()` path.

## Images as input, OCR, and visual citations (2026-09-25)

Prompted by a real downstream need: images as `parseFile()`/`parseBytes()` input, OCR via an
HTTP server (EasyOCR specifically), and the "visual citations" pattern (highlight where a search
match sits on the rendered page). Expected this to require real wiring work; it didn't — every
`Config` field needed (`ocrEnabled`, `ocrServerUrl`, `ocrServerHeaders`, `ocrLanguage`,
`tessdataPath`, `ocrFailureFatal`, `ocrHedgeDelaysMs`, `numWorkers`) was already wired in the
2026-09-24 "wire everything remaining" pass, and `ParseResult::search()` already existed. The
actual gap was verification and documentation: `README.md` carried a stale claim ("images is not
tested and not provided so far") left over from before upstream dropped the ImageMagick
dependency for image→PDF conversion (`crates-v2.8.0` — see
`crates/liteparse/src/conversion.rs`, native Rust raster→PDF embedding, DPI read from the
image's own metadata with a 150 DPI fallback). `LiteParse.php`'s `parseFile()`/`parseBytes()`
docblocks still said image conversion "requires LibreOffice and/or ImageMagick", which was true
of an earlier liteparse version but not this one.

Verified for real rather than trusting the docs correction alone:

1. Built and ran the reference EasyOCR server from `../liteparse/ocr/easyocr` via a local,
   deliberately uncommitted `compose.yaml` (`docker compose up -d easyocr`, port 8828) — the
   user had already built the image before this session started. Kept out of the repo on
   purpose (decided explicitly, not by default): it's a personal dev convenience pointing at an
   image built from a sibling checkout, not something a fresh clone of this repo can run as-is.
2. Rendered page 1 of the committed fixture (`tests/fixtures/pdf-headings-images-tables.pdf`) to
   a PNG via `screenshotFile()` at 200 DPI — a real raster with no PDF structure and no embedded
   text, standing in for a photographed/scanned page.
3. Fed that PNG straight into `parseFile()` (never touching the PDF) with `ocrEnabled: false`
   (the default) — confirmed `json()['pages'][0]['text']` comes back empty, not an error. Easy
   to trip over: pointing `parseFile()` at a bare image without OCR configured silently yields
   nothing.
4. Fed the same PNG in again with `ocrEnabled: true, ocrServerUrl: 'http://localhost:8828/ocr'`
   — got back the full recovered text (headings, paragraphs, the ordered/unordered list nesting,
   OCR mangling a few ligatures as expected — e.g. "In this report; we will write" for "In this
   report, we will write", a semicolon/comma OCR confusion, not a bug in this binding). Text
   items carried `confidence` (84% average on this clean synthetic render) and `font_name ===
   'OCR'` in place of real font metrics, exactly as `Config::$extractTextMetadata`'s docblock
   already promised. First request after a language switch took ~37s (EasyOCR's one-time reader
   init for that language); irrelevant to correctness, worth knowing before assuming something
   hung.
5. For visual citations: parsed the fixture, called `search('lorem ipsum', caseSensitive:
   false)` (5 matches across both pages, including one italicized inline instance), rendered the
   matched pages via `screenshotFile()` at the *same* DPI as the parse, and drew a semi-transparent
   yellow filled rectangle over each match's bbox scaled by `dpi / 72` — the same point→pixel
   scaling already established for `Screenshot::$rects` in `examples/screenshot/`. Visually
   confirmed via `Read` on the output PNG: every highlight box landed exactly on the matched text,
   including the italic "lorem ipsum" inside a sentence, with no drift.

Shipped as two new runnable examples (`examples/ocr/ocr.php`, `examples/visual-citations/
visual-citations.php`) plus the doc corrections (`README.md`'s stale note, `LiteParse.php`'s
`parseFile()`/`parseBytes()` docblocks). No `Config`/Rust/FFI changes — nothing was missing at
that layer, only at the verification-and-documentation layer. Both examples' docblocks and
README point at liteparse's own OCR guide/reference-server repo for standing one up, rather than
at a local `compose.yaml` created for convenience that must be kept as git ignored. No doc in
this repo should read as if it ships or is guaranteed present.