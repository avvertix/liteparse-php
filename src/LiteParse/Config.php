<?php

declare(strict_types=1);

namespace LiteParse;

/**
 * Mirrors liteparse's `LiteParseConfig` (Rust), passed across the FFI
 * boundary as JSON via `LiteParse::__construct`. Field names in `toJson()`
 * match the Rust struct's serde field names exactly.
 */
final class Config
{
    /**
     * @param  string  $ocrLanguage  Tesseract-format language code ("eng", "fra", "deu", ...).
     * @param  bool  $ocrEnabled  Whether OCR runs on text-sparse pages and embedded images.
     *                            This binding ships without the built-in Tesseract engine, so
     *                            OCR requires $ocrServerUrl to also be set.
     * @param  ?string  $ocrServerUrl  HTTP OCR server URL (see liteparse/OCR_API_SPEC.md and
     *                                 the easyocr/paddleocr/suryaocr reference servers).
     * @param  array<int, array{0: string, 1: string}>  $ocrServerHeaders  Extra HTTP headers
     *                                                                     sent with every OCR request, e.g. [["Authorization", "Bearer ..."]].
     * @param  ?string  $tessdataPath  Unused without the bundled Tesseract engine; kept for
     *                                 config round-trip fidelity.
     * @param  int  $maxPages  Maximum number of pages to parse.
     * @param  ?string  $targetPages  Specific pages to parse, e.g. "1-5,10,15-20". Null = all pages.
     * @param  float  $dpi  DPI for rendering pages (used for OCR and screenshots).
     * @param  bool  $preserveVerySmallText  Keep very small text that would normally be filtered out.
     * @param  ?string  $password  Password for encrypted/protected documents.
     * @param  bool  $quiet  Suppress liteparse's stderr progress logging.
     * @param  int  $numWorkers  Number of concurrent OCR workers.
     * @param  bool  $extractLinks  Extract hyperlink annotations into markdown `[text](url)` output.
     * @param  bool  $ocrFailureFatal  Whether a systemic OCR failure aborts the whole parse.
     * @param  int[]  $ocrHedgeDelaysMs  OCR request-hedging schedule (ms) for the HTTP OCR engine.
     * @param  bool  $emitWordBoxes  Emit per-word sub-boxes on each TextItem.
     * @param  bool  $extractImages  Extract embedded image bytes/metadata into `ParseResult.images`.
     * @param  ?string  $imageOutputDir  Directory where extracted embedded images are written.
     *                                   Requires $extractImages.
     * @param  bool  $extractAnnotations  Extract all PDF annotations into each parsed page.
     * @param  ?array{top: float, right: float, bottom: float, left: float}  $cropBox  Restrict
     *         output to a sub-region of every page, as fractions cropped from each side. Null
     *         (default) keeps the whole page.
     * @param  bool  $skipDiagonalText  Drop diagonal (skewed) text items more than 2° off the
     *                                  nearest right angle.
     * @param  bool  $includeComplexity  Compute per-page complexity signals during parse and
     *                                   attach them as a `complexity` object on each page in
     *                                   `ParseResult::json()`/`jsonString()` — text/image coverage,
     *                                   garbled-text detection, OCR reasons, and a nested `layout`
     *                                   object (column count, table/figure counts + coverage).
     *                                   Default off: the walk this runs is only worth paying for
     *                                   when the signals are actually consumed.
     * @param  bool  $keepHeadersFooters  Keep running header/footer chrome (and single-page chrome
     *                                    like "Page N of M") in `markdown()` output instead of
     *                                    stripping it. Only affects Markdown output.
     * @param  bool  $extractVectorGraphics  Expose page-scoped vector path data (shapes and merged
     *                                       horizontal/vertical lines) in parse results.
     */
    public function __construct(
        public readonly string $ocrLanguage = 'eng',
        public readonly bool $ocrEnabled = false,
        public readonly ?string $ocrServerUrl = null,
        public readonly array $ocrServerHeaders = [],
        public readonly ?string $tessdataPath = null,
        public readonly int $maxPages = 1000,
        public readonly ?string $targetPages = null,
        public readonly float $dpi = 150.0,
        public readonly OutputFormat $outputFormat = OutputFormat::Json,
        public readonly bool $preserveVerySmallText = false,
        public readonly ?string $password = null,
        public readonly bool $quiet = true,
        public readonly int $numWorkers = 1,
        public readonly ImageMode $imageMode = ImageMode::Placeholder,
        public readonly bool $extractLinks = true,
        public readonly bool $ocrFailureFatal = true,
        public readonly array $ocrHedgeDelaysMs = [],
        public readonly bool $emitWordBoxes = false,
        public readonly bool $extractImages = false,
        public readonly ?string $imageOutputDir = null,
        public readonly bool $extractAnnotations = false,
        public readonly ?array $cropBox = null,
        public readonly bool $skipDiagonalText = false,
        public readonly bool $includeComplexity = false,
        public readonly bool $keepHeadersFooters = false,
        public readonly bool $extractVectorGraphics = false,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'ocr_language' => $this->ocrLanguage,
            'ocr_enabled' => $this->ocrEnabled,
            'ocr_server_url' => $this->ocrServerUrl,
            'ocr_server_headers' => $this->ocrServerHeaders,
            'tessdata_path' => $this->tessdataPath,
            'max_pages' => $this->maxPages,
            'target_pages' => $this->targetPages,
            'dpi' => $this->dpi,
            'output_format' => $this->outputFormat->value,
            'preserve_very_small_text' => $this->preserveVerySmallText,
            'password' => $this->password,
            'quiet' => $this->quiet,
            'num_workers' => $this->numWorkers,
            'image_mode' => $this->imageMode->value,
            'extract_images' => $this->extractImages,
            'image_output_dir' => $this->imageOutputDir,
            'extract_links' => $this->extractLinks,
            'extract_annotations' => $this->extractAnnotations,
            'ocr_failure_fatal' => $this->ocrFailureFatal,
            'ocr_hedge_delays_ms' => $this->ocrHedgeDelaysMs,
            'emit_word_boxes' => $this->emitWordBoxes,
            'crop_box' => $this->cropBox,
            'skip_diagonal_text' => $this->skipDiagonalText,
            'include_complexity' => $this->includeComplexity,
            'keep_headers_footers' => $this->keepHeadersFooters,
            'extract_vector_graphics' => $this->extractVectorGraphics,
        ], JSON_THROW_ON_ERROR);
    }
}
