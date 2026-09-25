<?php

declare(strict_types=1);

namespace LiteParse\Tests\Unit;

use LiteParse\Config;
use PHPUnit\Framework\TestCase;

/**
 * `Config::toJson()` is pure PHP (no FFI/native library involved), so this
 * suite runs as a fast unit test rather than an integration one.
 */
final class ConfigTest extends TestCase
{
    public function test_ocr_disabled_by_default_with_no_server_url(): void
    {
        $json = json_decode((new Config)->toJson(), associative: true);

        $this->assertFalse($json['ocr_enabled']);
        $this->assertNull($json['ocr_server_url']);
    }

    public function test_setting_ocr_server_url_implies_ocr_enabled(): void
    {
        $json = json_decode(
            (new Config(ocrServerUrl: 'http://localhost:8828/ocr'))->toJson(),
            associative: true
        );

        $this->assertTrue($json['ocr_enabled']);
        $this->assertSame('http://localhost:8828/ocr', $json['ocr_server_url']);
    }

    public function test_empty_string_ocr_server_url_does_not_imply_ocr_enabled(): void
    {
        $json = json_decode((new Config(ocrServerUrl: ''))->toJson(), associative: true);

        $this->assertFalse($json['ocr_enabled']);
    }

    public function test_explicit_ocr_enabled_false_overrides_a_configured_server_url(): void
    {
        // Explicit true/false always wins over the null-inference — e.g. keeping a server URL
        // configured (for a toggle switched elsewhere) without OCR actually running.
        $json = json_decode(
            (new Config(ocrEnabled: false, ocrServerUrl: 'http://localhost:8828/ocr'))->toJson(),
            associative: true
        );

        $this->assertFalse($json['ocr_enabled']);
        $this->assertSame('http://localhost:8828/ocr', $json['ocr_server_url']);
    }

    public function test_ocr_enabled_true_without_a_server_url(): void
    {
        $json = json_decode((new Config(ocrEnabled: true))->toJson(), associative: true);

        $this->assertTrue($json['ocr_enabled']);
        $this->assertNull($json['ocr_server_url']);
    }
}
