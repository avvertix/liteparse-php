# liteparse-php

Native PHP bindings for [LiteParse](https://github.com/run-llama/liteparse), local PDF and document parsing with spatial text extraction powered by Rust and PDFium.

Given a PDF this package extracts text with bounding boxes and renders it as structured JSON, plain text, or layout-aware Markdown (headings, lists, tables, figure references). The parsing happens directly from PHP, via a compiled Rust `cdylib` loaded through PHP's `FFI` extension.


> [!NOTE]
> The native bindings are tested only for PDF files. Support for DOC/DOCX/XLS/XLSX/PPT/PPTX, and images is not tested and not provided so far.


## Requirements

- PHP 8.3+ with `ext-ffi` enabled (`ffi.enable=On` in php.ini; CLI defaults to on)
- A compiled `liteparse_php` native library for your platform (see [Installation](#installation))
- Optional, for OCR: an HTTP OCR server implementing [`LiteParse OCR_API_SPEC.md`](https://github.com/run-llama/liteparse/blob/main/OCR_API_SPEC.md) (reference servers for EasyOCR/PaddleOCR/SuryaOCR ship in the [LiteParse repo](https://github.com/run-llama/liteparse/tree/main/ocr)). This binding does **not** bundle the Tesseract engine as would increase the complexity of the build and distribution pipeline, point `Config::$ocrServerUrl` at a server instead.

## Installation

Get the package via Composer and install the native library for your platform.

```bash
composer require avvertix/liteparse-php
vendor/bin/liteparse-php install
```

`install` downloads the compiled `liteparse_php` library and its PDFium dependency for your platform from the package's GitHub Releases into `vendor/avvertix/liteparse-php/lib/`. The specific installed versions are recorded in a `natives.lock` file in the root of your project, commit this alongside `composer.lock` to install the same version of the compiled binary. Run `vendor/bin/liteparse-php update` after upgrading the package to fetch the matching native library.

Upgrading from an earlier version? See [`UPGRADE.md`](./UPGRADE.md) for what changed and what needs attention.



## Quick start

```php
use LiteParse\Config;
use LiteParse\OutputFormat;
use LiteParse\LiteParse;

$parser = new LiteParse(new Config(outputFormat: OutputFormat::Markdown));

$result = $parser->parseFile('/path/to/document.pdf');

echo $result->pageCount();   // int
echo $result->text();        // plain text, "--- Page N ---" headers
echo $result->markdown();    // headings/lists/tables/figure refs reconstructed from layout
echo json_encode($result->json());  // structured per-page text items: bbox + font size + fill/stroke color, per item
echo json_encode($result->lines());  // structured per-page projected lines: merged bbox + style + column geometry
```

`json()` returns `liteparse`'s full per-item `TextItem` — not the lean `{text, x, y, width, height}` shape the upstream `lit` CLI's own `--format json` produces. Each item carries its bounding box together with `font_size` and `fill_color`/`stroke_color` (ARGB hex, e.g. `"ff000000"`) on the same record, plus rotation, links, strikethrough, and OCR confidence where applicable. Font/color fields are only populated for native PDF text; OCR-derived items carry `null` there instead.

`lines()` sits between `json()` and `markdown()`: each entry is a merged visual line (one or more `TextItem`s sharing a baseline) carrying its own bounding box, dominant font/style, and `region_path` — the xy-cut column/region position `liteparse` uses internally to group paragraphs and tables. Each line's `spans` field keeps the original `TextItem`s that merged into it, so per-run font/color survives even where the line's own `text` concatenates multiple items. Unlike `markdown()`, nothing here is reformatted or dropped when the heuristic table/heading detection misfires — you get the raw geometry and can reconstruct rows/columns/headings yourself from `region_path` and bbox positions. There is no heading/paragraph/list "role" label at this layer.

With `Config::$extractDocumentMetadata` and `Config::$continueOnPageError` on, `json()`'s top level also carries `doc_meta` (dates, encryption, signatures, incremental-save markers, raw XMP) and `page_errors` (page-level extraction failures that didn't abort the parse); `total_pages` (source page count before `maxPages`/`targetPages` truncation) is always present.

Every `ParseResult` accessor (`text()`, `markdown()`, `json()`, `lines()`, `blocks()`, `flatBlocks()`, `images()`) renders on demand from the same underlying parsed pages.

### Building your own document model: `blocks()` over `markdown()`

`markdown()` reconstructs headings/lists/tables/figure references as rendered text — meant for humans, LLM prompts, or diffing, not for parsing back into a structure. Re-parsing it loses the bounding boxes `liteparse` already computed, and stands a text convention (like a `-----` thematic break for page breaks) in for real document structure.

If you're building your own page/block/document model — the actual reason most integrations touch `markdown()` — start from `Config::$extractBlocks` and `ParseResult::blocks()`/`flatBlocks()` instead:

```php
use LiteParse\Config;
use LiteParse\LiteParse;
use LiteParse\Layout\BlockKind;

$parser = new LiteParse(new Config(extractBlocks: true));
$result = $parser->parseFile('/path/to/document.pdf');

// Grouped by page — the default, familiar shape:
foreach ($result->blocks() as $page) {
    foreach ($page['blocks'] as $block) {
        if ($block->kind === BlockKind::Heading) {
            echo str_repeat('#', $block->level ?? 1)." {$block->text}\n";
        }
    }
}

// Or flattened across the whole document, each block still tagged with its own page:
foreach ($result->flatBlocks() as $block) {
    printf("p%d %s: %s\n", $block->pageNumber, $block->kind->value, $block->text ?? '');
}
```

Each `Block` carries a `bbox` (the union of every source line that fed it — including a bbox on every table cell), reading order matching what `markdown()` renders, and kind-specific fields (`level`/`ordered`/`marker` for lists, `header`/`rows` for tables, `id`/`format` for figures — join a figure block's `id` against `ParseResult::images()` to get its extracted file). See [`Block`](./src/LiteParse/Layout/Block.php) for the full field list. Independent of `outputFormat`; enabling `extractBlocks` never changes the rendered Markdown.

With `Config::$extractImages` (and optionally `imageOutputDir`) on, `ParseResult::images()` returns each embedded image's metadata — `id`, `path` (when written to disk), `bbox`, dimensions, `format`, and `duplicateOf` for repeated images (e.g. a logo reused across pages) — never pixel bytes.

## Features

- **`LiteParse::parseFile()` / `parseBytes()`** — parse from a file path or an in-memory buffer (e.g. a PDF downloaded over the network).
- **`LiteParse::isComplexFile()` / `isComplexBytes()`** — a cheap per-page pre-check (no OCR, no rendering) reporting whether each page looks scanned, sparse, garbled, or image-heavy — useful for deciding whether a document needs OCR before committing to a full parse.
- **`LiteParse::screenshotFile()` / `screenshotBytes()`** — render selected pages (or the whole document) to PNG bytes. With `Config::$extractScreenshots` on, `ParseResult::screenshots()` returns the same pages' PNGs from the parse that already happened, instead of rendering a second time. Every `Screenshot` also carries `isSolidFill` (blank-page detection, always computed) and `rects` (solid rectangles/lines detected in the raster, needs `Config::$detectScreenshotRects`).
- **`ParseResult::search()`** — search already-parsed text for phrase matches, with bounding boxes, merged across text items that were split mid-phrase.

```php
// Complexity pre-check
$stats = $parser->isComplexFile('scan.pdf');
$needsOcr = array_filter($stats, fn ($page) => $page['needs_ocr']);

// Screenshots
$screenshots = $parser->screenshotFile('doc.pdf', pageNumbers: [1, 2]); // null = all pages
foreach ($screenshots as $shot) {
    file_put_contents("page-{$shot->pageNumber}.png", $shot->bytes);
}

// Search
$result = $parser->parseFile('doc.pdf');
foreach ($result->search('quarterly revenue') as $match) {
    printf("page %d at (%.0f, %.0f)\n", $match['page_number'], $match['x'], $match['y']);
}
```

See [`examples/`](./examples/) for runnable scripts.

## Configuration

`Config` mirrors `liteparse`'s Rust `LiteParseConfig` field-for-field:

| Field | Default | Notes |
|---|---|---|
| `ocrLanguage` | `'eng'` | Tesseract-format language code |
| `ocrEnabled` | `false` | Requires `ocrServerUrl` — this binding has no built-in OCR engine |
| `ocrServerUrl` | `null` | HTTP OCR server URL |
| `ocrServerHeaders` | `[]` | `[[name, value], ...]` sent with every OCR request |
| `maxPages` | `1000` | |
| `targetPages` | `null` | e.g. `"1-5,10,15-20"`; `null` = all pages |
| `dpi` | `150.0` | Used for OCR and screenshots |
| `outputFormat` | `OutputFormat::Json` | Informational only in this binding — `text()`/`markdown()`/`json()` are always available regardless |
| `preserveVerySmallText` | `false` | |
| `password` | `null` | For encrypted/protected documents |
| `quiet` | `true` | Suppresses `liteparse`'s stderr progress logging (Rust default is `false`) |
| `numWorkers` | `1` | Concurrent OCR requests to the HTTP server |
| `imageMode` | `ImageMode::Placeholder` | Affects `markdown()` image references only |
| `extractLinks` | `true` | Hyperlinks as `[text](url)` in markdown |
| `extractImages` | `false` | Extract embedded image metadata into `ParseResult::images()` (never pixel bytes) |
| `imageOutputDir` | `null` | Directory where extracted embedded images are written; requires `extractImages` |
| `extractAnnotations` | `false` | Extract all PDF annotations into each parsed page |
| `cropBox` | `null` | Restrict output to a sub-region of every page: `['top' => ..., 'right' => ..., 'bottom' => ..., 'left' => ...]` fractions |
| `skipDiagonalText` | `false` | Drop text items rotated more than 2° off the nearest right angle |
| `ocrFailureFatal` | `true` | Abort the whole parse on systemic OCR failure vs. return degraded results |
| `ocrHedgeDelaysMs` | `[]` | Request-hedging schedule for the HTTP OCR engine |
| `emitWordBoxes` | `false` | Per-word sub-boxes on each text item (roughly doubles payload size) |
| `includeComplexity` | `false` | Attach a `complexity` object (text/image coverage, OCR reasons, layout signals) to each page in `json()` |
| `keepHeadersFooters` | `false` | Keep running headers/footers in `markdown()` instead of stripping them |
| `extractVectorGraphics` | `false` | Expose page-scoped vector path data (shapes, merged lines) in parse results |
| `extractBlocks` | `false` | Populate `ParseResult::blocks()`/`flatBlocks()` (and `json()`'s per-page `blocks`) — the recommended way to build your own document model, see [above](#building-your-own-document-model-blocks-over-markdown) |
| `extractDocumentMetadata` | `false` | Populate `json()`'s top-level `doc_meta` (dates, encryption, signatures, incremental-save markers, raw XMP) |
| `extractScreenshots` | `false` | Render every page to PNG during `parseFile()`/`parseBytes()`, available via `ParseResult::screenshots()` |
| `continueOnPageError` | `false` | Continue past a page-level extraction failure instead of aborting the parse; failures land in `json()`'s top-level `page_errors` |
| `extractFormFields` | `false` | Attach each page's AcroForm widget fields/values to `json()`'s per-page `form_fields` |
| `extractStructureTree` | `false` | Attach each page's tagged-PDF logical structure tree to `json()`'s per-page `structure_tree` |
| `extractContentBounds` | `false` | Attach each page's `content_bounds` (union bbox of its top-level content) to `json()` |
| `extractXfaPackets` | `false` | Extract raw XFA packets from XFA form documents into `json()`'s top-level `xfa_packets` |
| `extractTextMetadata` | `false` | Include `char_codes`/`trailing_space_generated` on every text item in `json()` |
| `detectScreenshotRects` | `false` | Detect solid rectangles/lines in rendered screenshots, on `Screenshot::$rects` (full-bitmap scan per page) |
| `renderFormFields` | `false` | Draw AcroForm field appearances into rendered rasters — initializes a PDFium form-fill environment and runs the document's open/JS actions |
| `pageOrientationCorrections` | `[]` | `[['page' => 1, 'angle' => 90], ...]` — counter-rotate specific pages by a caller-supplied clockwise angle (0/90/180/270) |

## How it works

LiteParse Rust create is exposed as C library to be consumed by the PHP Foreign Function Interface (FFI). The shared library is defined in `rust/` exposing handles for the parser/result/screenshot-list lifecycle, plus configuration and exceptions. LiteParse's API is `async` (tokio-based); the FFI layer owns a single process-wide tokio runtime and `block_on`s each call, since PHP FFI calls are synchronous. `cbindgen` generates the committed `include/liteparse_php.h` header that PHP's `FFI::cdef()` loads.

PDFium (a separate native dependency `liteparse` links against) is discovered at runtime by `pdfium-sys`'s loader relative to whichever shared library loaded it.

## Development

```bash
./scripts/build.sh          # cargo build --release, stage lib/
composer install
composer test               # PHPUnit, against the compiled library
composer lint               # PHPStan
```

Adding a new FFI function: add the `extern "C"` function in `rust/src/ffi/`, rebuild (`cbindgen` regenerates `include/liteparse_php.h` automatically via `build.rs`), add the corresponding `@method` annotation to `LiteParseFfi`, and wrap it in a PHP class.


### Building from source

If you're working in this repository directly (or no prebuilt release exists yet for your platform):

```bash
./scripts/build.sh release   # cargo build --release + stage lib/liteparse_php.* and lib/libpdfium.*
composer install
composer test
```

`scripts/build.sh` compiles the Rust crate in `rust/` and copies the resulting native library, plus its PDFium runtime dependency, into `lib/`.

## License

The project is dual licenced. The PHP wrapper code is licenced under [MIT](./LICENSE.md). The Rust binding to expose via FFI are licenced under [Apache-2.0](./rust/LICENCE.md).
