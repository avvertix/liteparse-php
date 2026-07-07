<?php

declare(strict_types=1);

namespace LiteParse;

use FFI\CData;

/**
 * Singleton that loads and holds the FFI instance for the liteparse_php shared library.
 *
 * Resolves the compiled library and header from the `lib/` and `include/` directories
 * relative to the package root. PHP must have `ext-ffi` enabled.
 *
 * @method ?string liteparse_get_last_error() Auto-converted by ext-ffi: non-null `const char*` decays to a PHP string.
 * @method void liteparse_clear_last_error()
 * @method ?CData liteparse_parser_new(?CData $config_json)
 * @method void liteparse_parser_free(CData $handle)
 * @method ?CData liteparse_parser_parse_file(CData $handle, CData $path)
 * @method ?CData liteparse_parser_parse_bytes(CData $handle, ?CData $data, int $len)
 * @method ?CData liteparse_result_json(CData $handle)
 * @method ?CData liteparse_result_lines_json(CData $handle)
 * @method ?CData liteparse_result_text(CData $handle)
 * @method ?CData liteparse_result_markdown(CData $handle)
 * @method int liteparse_result_page_count(CData $handle)
 * @method void liteparse_result_free(CData $handle)
 * @method ?CData liteparse_parser_is_complex_file(CData $handle, CData $path)
 * @method ?CData liteparse_parser_is_complex_bytes(CData $handle, ?CData $data, int $len)
 * @method ?CData liteparse_parser_screenshot_file(CData $handle, CData $path, ?CData $page_numbers, int $page_numbers_len)
 * @method ?CData liteparse_parser_screenshot_bytes(CData $handle, ?CData $data, int $len, ?CData $page_numbers, int $page_numbers_len)
 * @method int liteparse_screenshot_list_len(CData $handle)
 * @method int liteparse_screenshot_page_number(CData $handle, int $idx)
 * @method int liteparse_screenshot_width(CData $handle, int $idx)
 * @method int liteparse_screenshot_height(CData $handle, int $idx)
 * @method ?CData liteparse_screenshot_bytes(CData $handle, int $idx, CData $out_len)
 * @method void liteparse_screenshot_list_free(CData $handle)
 * @method ?CData liteparse_search(CData $handle, CData $phrase, int $case_sensitive)
 * @method void liteparse_string_free(?CData $ptr)
 * @method ?CData new(string $type, bool $owned = true, bool $persistent = false)
 * @method CData cast(string $type, CData $ptr)
 */
final class LiteParseFfi
{
    private static ?self $singleton = null;

    private function __construct(
        private readonly \FFI $ffi,
    ) {}

    /**
     * Return the shared FFI instance, loading it on first call.
     *
     * @throws \RuntimeException if the library or header cannot be found.
     */
    public static function instance(): self
    {
        if (self::$singleton === null) {
            self::$singleton = new self(self::load());
        }

        return self::$singleton;
    }

    /** @param mixed[] $args */
    public function __call(string $name, array $args): mixed
    {
        return $this->ffi->$name(...$args);
    }

    private static function load(): \FFI
    {
        $packageRoot = dirname(__DIR__, 2);
        $headerFile = $packageRoot.'/include/liteparse_php.h';
        $libFile = self::resolveLibrary($packageRoot);

        if (! file_exists($headerFile)) {
            throw new \RuntimeException(
                "liteparse_php header not found at: {$headerFile}\n".
                'Build the Rust library first: ./scripts/build.sh'
            );
        }

        if ($libFile === null) {
            throw new \RuntimeException(
                "liteparse_php shared library not found in: {$packageRoot}/lib/\n".
                'Build the Rust library first: ./scripts/build.sh'
            );
        }

        return \FFI::cdef(self::processHeader($headerFile), $libFile);
    }

    /**
     * Locate the platform-appropriate shared library file. PDFium's own
     * shared library must sit alongside it in the same directory (see
     * scripts/copy-pdfium.sh) — pdfium-sys's runtime loader discovers it
     * relative to this module's own path.
     */
    private static function resolveLibrary(string $root): ?string
    {
        $libDir = $root.'/lib';

        $candidates = match (PHP_OS_FAMILY) {
            'Windows' => [
                $libDir.'/liteparse_php.dll',
            ],
            'Darwin' => [
                $libDir.'/libliteparse_php.dylib',
            ],
            default => [
                $libDir.'/libliteparse_php.so',
            ],
        };

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Strip preprocessor directives that \FFI::cdef does not support.
     */
    private static function processHeader(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read header file: {$path}");
        }

        return preg_replace('/^#.*$/m', '', $content);
    }

    /**
     * Convert a PHP string to a null-terminated C string (char*).
     */
    public static function cstring(string $s): CData
    {
        $ffi = self::instance();
        $len = strlen($s);
        $buf = $ffi->new('char['.($len + 1).']', false);
        if ($buf === null) {
            throw new Exception\LiteParseException('FFI memory allocation failed');
        }
        \FFI::memcpy($buf, $s, $len);
        $buf[$len] = "\0";

        return $buf;
    }

    /**
     * Convert an owned, non-const `char *` result (a `CData` per ext-ffi's
     * conversion rules — unlike the `const char*` used only by
     * `liteparse_get_last_error`) into a PHP string and free the underlying
     * Rust allocation via `liteparse_string_free`. Throws the last FFI error
     * if `ptr` is null.
     *
     * @throws Exception\LiteParseException on null pointer
     */
    public static function consumeOwnedString(?CData $ptr, string $context = ''): string
    {
        $ffi = self::instance();
        if ($ptr === null || \FFI::isNull($ptr)) {
            self::throwLastError($context);
        }

        $value = \FFI::string($ptr);
        $ffi->liteparse_string_free($ptr);

        return $value;
    }

    /**
     * Throw a LiteParseException with the last FFI error message, then clear it.
     *
     * @throws Exception\LiteParseException always
     */
    public static function throwLastError(string $context = ''): never
    {
        $ffi = self::instance();
        // `const char *` returns are auto-converted by ext-ffi: a non-null
        // pointer comes back as a plain PHP string, a null pointer as PHP
        // null. Unlike the non-const `char *` results elsewhere in this
        // binding, there is no CData to unwrap with FFI::string() here.
        $raw = $ffi->liteparse_get_last_error();
        $msg = is_string($raw) ? $raw : 'unknown error';
        $ffi->liteparse_clear_last_error();
        $prefix = $context !== '' ? "{$context}: " : '';
        throw new Exception\LiteParseException("{$prefix}{$msg}");
    }

    /**
     * Assert that a CData handle is non-null; throw with the last error otherwise.
     *
     * @throws Exception\LiteParseException on null handle
     */
    public static function assertHandle(?CData $handle, string $context = ''): CData
    {
        if ($handle === null || \FFI::isNull($handle)) {
            self::throwLastError($context);
        }

        return $handle;
    }
}
