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
     * Parse a document from a file path. Non-PDF files (DOCX, XLSX, PPTX,
     * images, ...) are converted to PDF automatically, which requires
     * LibreOffice and/or ImageMagick to be installed on the host.
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
     * Parse a document from raw in-memory bytes (e.g. a PDF downloaded over
     * the network).
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

        return $this->collectScreenshots($listHandle);
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

        return $this->collectScreenshots($listHandle);
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

    /** @return Screenshot[] */
    private function collectScreenshots(CData $listHandle): array
    {
        $ffi = LiteParseFfi::instance();
        $count = $ffi->liteparse_screenshot_list_len($listHandle);
        $lenPtr = $ffi->new('size_t');

        $screenshots = [];
        for ($i = 0; $i < $count; $i++) {
            $bytesPtr = $ffi->liteparse_screenshot_bytes($listHandle, $i, \FFI::addr($lenPtr));
            $bytes = ($bytesPtr !== null && ! \FFI::isNull($bytesPtr))
                ? \FFI::string($bytesPtr, (int) $lenPtr->cdata)
                : '';

            $screenshots[] = new Screenshot(
                pageNumber: $ffi->liteparse_screenshot_page_number($listHandle, $i),
                width: $ffi->liteparse_screenshot_width($listHandle, $i),
                height: $ffi->liteparse_screenshot_height($listHandle, $i),
                bytes: $bytes,
            );
        }

        $ffi->liteparse_screenshot_list_free($listHandle);

        return $screenshots;
    }
}
