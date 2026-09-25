# Upgrading

## v0.1.1 → current

No breaking changes to the PHP API. Every addition since `v0.1.1` is either a new method,
a new `Config` constructor parameter appended after the existing ones (all with defaults,
so both named- and positional-argument callers keep working unmodified), or a new key in
`json()`'s output alongside the existing ones. Nothing was renamed, removed, or had its
signature changed. Verified by diffing `src/` against the `v0.1.1` tag: every changed file
is purely additive except a few re-wrapped doc comments.

That said, three things are worth deliberate attention before you upgrade:

### 1. Update the native library, not just the Composer package

```bash
composer update avvertix/liteparse-php
vendor/bin/liteparse-php update
```

This release adds new exported C symbols (`liteparse_screenshot_is_solid_fill`,
`liteparse_screenshot_rects_json`). `LiteParseFfi.php`'s `\FFI::cdef()` call declares every
symbol in `include/liteparse_php.h` up front — if the compiled `lib/liteparse_php.*` on disk
is older than the header (i.e. you updated the Composer package but not the native binary),
`FFI::cdef()` fails outright on the very first call, not just when a new method is touched.
This is true of any release that adds FFI symbols, not unique to this one — but it's easy to
forget since most of the API surface added here is header-only from PHP's side.

### 2. `Config::$keepHeadersFooters` behavior changed — a bug fix, not a new feature

If you already set `keepHeadersFooters: true`, **your `markdown()` output will change** after
this upgrade, with no code change on your end. The flag was a silent no-op since it was first
added: the old FFI code always called liteparse's markdown renderer with header/footer
stripping forced on, regardless of what you configured. It's wired correctly now — repeating
headers/footers (and single-page chrome like "Page N of M") are retained when the flag is set,
suppressed when it isn't, matching the documented behavior for the first time. If you have
snapshot tests asserting on `markdown()` output with this flag set, expect to regenerate them.

### 3. The `liteparse` Rust dependency moved 2.14.3 → 2.14.7

A minor-version bump of the underlying parser, not of this package, but it can still shift
`markdown()`/`json()` output on real documents — classification heuristics (headings, tables,
lists) improved incrementally in that range. If you have golden-file or snapshot tests
comparing parse output byte-for-byte, re-run them; don't assume identical output across the
bump. (The one known classification bug — a table's header row occasionally landing outside
the `table` block — is unchanged by this bump either way, for what it's worth.)

### Minor: `json()`'s top-level shape always has four more keys now

`images`, `xfa_packets`, `creator`, `producer` are present in every `json()`/`jsonString()`
response regardless of config (empty/`null` when their feature is off), alongside the
existing always-present `total_pages`/`doc_meta`/`page_errors`/`pages`. This doesn't break
normal array access, but if you validate the response against a strict JSON Schema with
`additionalProperties: false`, or assert `array_keys($json) === [...]` exactly, update that
check.

## What's new (all opt-in — nothing here requires any code change to keep working)

- **`ParseResult::blocks()` / `flatBlocks()`** — typed `Layout\Block` value objects
  (headings, paragraphs, lists, tables, figures, with bounding boxes), the recommended way
  to build your own document model instead of re-parsing `markdown()`. Needs
  `Config::$extractBlocks`. See the README's "Building your own document model" section.
- **`ParseResult::images()`** — extracted embedded-image metadata (`id`, `path`, `bbox`,
  dimensions, `format`, `duplicateOf`), joinable against a `figure` block's `id`. Needs
  `Config::$extractImages`.
- **`json()`'s per-page `form_fields`, `structure_tree`, `content_bounds`, `annotations`**
  — needs `Config::$extractFormFields` / `$extractStructureTree` / `$extractContentBounds`
  / `$extractAnnotations` respectively. See `examples/structure-tree/` for a worked example
  walking a tagged PDF's structure tree and resolving element text via `marked_content_ids`.
- **`json()`'s top-level `xfa_packets`, `creator`, `producer`** — needs
  `Config::$extractXfaPackets` for the first; the latter two are unconditional.
  `char_codes`/`trailing_space_generated` on text items need `Config::$extractTextMetadata`.
- **`Screenshot::$isSolidFill`** (blank-page detection, always computed) and **`$rects`**
  (solid rectangles/lines detected on the raster, needs `Config::$detectScreenshotRects`) —
  on every `Screenshot`, from `screenshotFile()`/`screenshotBytes()` and
  `ParseResult::screenshots()` alike. See `examples/screenshot/` for a worked example that
  draws detected rects as an overlay.
- **`Config::$renderFormFields`** — draws AcroForm field appearances into rendered rasters.
  Initializes a PDFium form-fill environment and runs the document's open/JS actions, so
  it's off by default even though the rest of this list isn't security-sensitive.
- **`Config::$pageOrientationCorrections`** — per-page clockwise-angle correction for callers
  with their own upstream orientation classifier.

For the full field-by-field shape of everything above, see `ParseResult::json()`'s docblock
(the authoritative source) or the README's configuration table.
