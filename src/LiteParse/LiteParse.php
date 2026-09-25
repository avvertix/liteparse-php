<?php

declare(strict_types=1);

namespace LiteParse;

use FFI\CData;

/**
 * A configured liteparse parser. Reuse the same instance across multiple
 * `parseFile`/`parseBytes` calls — the config is fixed at construction time.
 *
 * @example
 * $parser = new LiteParse(new Config(outputFormat: OutputFormat::Markdown));
 * $result = $parser->parseFile('/path/to/document.pdf');
 * echo $result->markdown();
 */
final class LiteParse
{
    private CData $handle;

    public function __construct(?Config $config = null)
    {
        $ffi = LiteParseFfi::instance();
        $configJson = ($config ?? new Config)->toJson();

        $this->handle = LiteParseFfi::assertHandle(
            $ffi->liteparse_parser_new(LiteParseFfi::cstring($configJson)),
            'LiteParse::__construct'
        );
    }

    public function __destruct()
    {
        LiteParseFfi::instance()->liteparse_parser_free($this->handle);
    }

    /**
     * Parse a document from a file path. Non-PDF files are converted to PDF
     * automatically first: office formats (DOCX, XLSX, PPTX, ...) need
     * LibreOffice installed on the host; images (JPG, PNG, GIF, BMP, TIFF,
     * WEBP, SVG) are converted natively in Rust, no external tool needed.
     *
     * A bare image carries no embedded text, so `text()`/`json()` come back
     * empty unless `Config::$ocrServerUrl` is set (which also turns OCR on
     * unless `Config::$ocrEnabled` says otherwise) — see `examples/ocr/`.
     *
     * @throws Exception\LiteParseException on failure.
     */
    public function parseFile(string $path): ParseResult
    {
        $ffi = LiteParseFfi::instance();
        $resultHandle = LiteParseFfi::assertHandle(
            $ffi->liteparse_parser_parse_file($this->handle, LiteParseFfi::cstring($path)),
            'LiteParse::parseFile'
        );

        return new ParseResult($resultHandle);
    }

    /**
     * Parse a document from raw in-memory bytes (e.g. a PDF, or an image,
     * downloaded over the network or held in memory already — no temp file
     * needed). Same format/conversion/OCR rules as `parseFile()`; the format
     * is sniffed from the bytes themselves rather than a file extension.
     *
     * @throws Exception\LiteParseException on failure.
     */
    public function parseBytes(string $bytes): ParseResult
    {
        $ffi = LiteParseFfi::instance();
        $len = strlen($bytes);
        $buf = $ffi->new('uint8_t['.max($len, 1).']', false);
        if ($buf === null) {
            throw new Exception\LiteParseException('FFI memory allocation failed');
        }
        if ($len > 0) {
            \FFI::memcpy($buf, $bytes, $len);
        }

        $resultHandle = LiteParseFfi::assertHandle(
            $ffi->liteparse_parser_parse_bytes($this->handle, $buf, $len),
            'LiteParse::parseBytes'
        );

        return new ParseResult($resultHandle);
    }

    /**
     * Cheap per-page complexity pre-check (no OCR, no page rendering) — use
     * this to decide whether a document needs OCR before committing to a
     * full parse.
     *
     * @return list<array<string, mixed>>
     *
     * @throws Exception\LiteParseException on failure.
     */
    public function isComplexFile(string $path): array
    {
        $ffi = LiteParseFfi::instance();
        $json = LiteParseFfi::consumeOwnedString(
            $ffi->liteparse_parser_is_complex_file($this->handle, LiteParseFfi::cstring($path)),
            'LiteParse::isComplexFile'
        );

        return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws Exception\LiteParseException on failure.
     */
    public function isComplexBytes(string $bytes): array
    {
        $ffi = LiteParseFfi::instance();
        $json = LiteParseFfi::consumeOwnedString(
            $ffi->liteparse_parser_is_complex_bytes($this->handle, ...$this->byteBuffer($bytes)),
            'LiteParse::isComplexBytes'
        );

        return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Render document pages to PNG screenshots.
     *
     * @param  ?int[]  $pageNumbers  1-based page numbers to render; null renders every page.
     * @return Screenshot[]
     *
     * @throws Exception\LiteParseException on failure.
     */
    public function screenshotFile(string $path, ?array $pageNumbers = null): array
    {
        $ffi = LiteParseFfi::instance();
        [$pagesBuf, $pagesLen] = $this->pageNumberBuffer($pageNumbers);

        $listHandle = LiteParseFfi::assertHandle(
            $ffi->liteparse_parser_screenshot_file(
                $this->handle,
                LiteParseFfi::cstring($path),
                $pagesBuf,
                $pagesLen
            ),
            'LiteParse::screenshotFile'
        );

        return LiteParseFfi::collectScreenshots($listHandle);
    }

    /**
     * @param  ?int[]  $pageNumbers  1-based page numbers to render; null renders every page.
     * @return Screenshot[]
     *
     * @throws Exception\LiteParseException on failure.
     */
    public function screenshotBytes(string $bytes, ?array $pageNumbers = null): array
    {
        $ffi = LiteParseFfi::instance();
        [$dataBuf, $dataLen] = $this->byteBuffer($bytes);
        [$pagesBuf, $pagesLen] = $this->pageNumberBuffer($pageNumbers);

        $listHandle = LiteParseFfi::assertHandle(
            $ffi->liteparse_parser_screenshot_bytes($this->handle, $dataBuf, $dataLen, $pagesBuf, $pagesLen),
            'LiteParse::screenshotBytes'
        );

        return LiteParseFfi::collectScreenshots($listHandle);
    }

    /** @internal Used by ParseResult/Screenshot helpers that need the raw handle. */
    public function ffiHandle(): CData
    {
        return $this->handle;
    }

    /** @return array{0: CData, 1: int} */
    private function byteBuffer(string $bytes): array
    {
        $ffi = LiteParseFfi::instance();
        $len = strlen($bytes);
        $buf = $ffi->new('uint8_t['.max($len, 1).']', false);
        if ($buf === null) {
            throw new Exception\LiteParseException('FFI memory allocation failed');
        }
        if ($len > 0) {
            \FFI::memcpy($buf, $bytes, $len);
        }

        return [$buf, $len];
    }

    /**
     * @param  ?int[]  $pageNumbers
     * @return array{0: ?CData, 1: int}
     */
    private function pageNumberBuffer(?array $pageNumbers): array
    {
        if ($pageNumbers === null || $pageNumbers === []) {
            return [null, 0];
        }

        $ffi = LiteParseFfi::instance();
        $values = array_values($pageNumbers);
        $count = count($values);
        $buf = $ffi->new("uint32_t[{$count}]", false);
        foreach ($values as $i => $pageNumber) {
            $buf[$i] = $pageNumber;
        }

        return [$buf, $count];
    }
}
