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

Every `ParseResult` accessor (`text()`, `markdown()`, `json()`, `lines()`) renders on demand from the same underlying parsed pages.

## Features

- **`LiteParse::parseFile()` / `parseBytes()`** — parse from a file path or an in-memory buffer (e.g. a PDF downloaded over the network).
- **`LiteParse::isComplexFile()` / `isComplexBytes()`** — a cheap per-page pre-check (no OCR, no rendering) reporting whether each page looks scanned, sparse, garbled, or image-heavy — useful for deciding whether a document needs OCR before committing to a full parse.
- **`LiteParse::screenshotFile()` / `screenshotBytes()`** — render selected pages (or the whole document) to PNG bytes.
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
| `ocrFailureFatal` | `true` | Abort the whole parse on systemic OCR failure vs. return degraded results |
| `ocrHedgeDelaysMs` | `[]` | Request-hedging schedule for the HTTP OCR engine |
| `emitWordBoxes` | `false` | Per-word sub-boxes on each text item (roughly doubles payload size) |

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
