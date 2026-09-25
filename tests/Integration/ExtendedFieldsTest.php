<?php

declare(strict_types=1);

namespace LiteParse\Tests\Integration;

use LiteParse\Config;
use LiteParse\LiteParse;
use LiteParse\ScreenshotRect;
use PHPUnit\Framework\TestCase;

final class ExtendedFieldsTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__).'/fixtures';
    }

    public function test_optional_fields_are_absent_by_default(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $json = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf')->json();

        $this->assertNull($json['xfa_packets']);
        foreach (['content_bounds', 'form_fields', 'structure_tree'] as $key) {
            $this->assertArrayNotHasKey($key, $json['pages'][0]);
        }
    }

    public function test_extract_content_bounds_populates_page_content_bounds(): void
    {
        $parser = new LiteParse(new Config(extractContentBounds: true, quiet: true));
        $json = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf')->json();

        $this->assertArrayHasKey('content_bounds', $json['pages'][0]);
        $this->assertArrayHasKey('width', $json['pages'][0]['content_bounds']);
        $this->assertGreaterThan(0, $json['pages'][0]['content_bounds']['width']);
    }

    public function test_extract_xfa_packets_is_empty_array_not_null_on_non_xfa_document(): void
    {
        $parser = new LiteParse(new Config(extractXfaPackets: true, quiet: true));
        $json = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf')->json();

        $this->assertSame([], $json['xfa_packets']);
    }

    public function test_extract_form_fields_and_structure_tree_attach_per_page_arrays(): void
    {
        $parser = new LiteParse(new Config(extractFormFields: true, extractStructureTree: true, quiet: true));
        $json = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf')->json();

        $this->assertIsArray($json['pages'][0]['form_fields']);
        $this->assertArrayHasKey('roots', $json['pages'][0]['structure_tree']);
        $this->assertNotEmpty($json['pages'][0]['structure_tree']['roots']);
        $this->assertSame('Document', $json['pages'][0]['structure_tree']['roots'][0]['type']);
    }

    public function test_extract_text_metadata_adds_char_codes_to_text_items(): void
    {
        $parser = new LiteParse(new Config(extractTextMetadata: true, quiet: true));
        $json = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf')->json();

        $this->assertArrayHasKey('char_codes', $json['pages'][0]['text_items'][0]);
        $this->assertNotEmpty($json['pages'][0]['text_items'][0]['char_codes']);
    }

    public function test_creator_and_producer_are_always_present_regardless_of_flags(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $json = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf')->json();

        $this->assertArrayHasKey('creator', $json);
        $this->assertArrayHasKey('producer', $json);
    }

    public function test_detect_screenshot_rects_and_is_solid_fill_on_screenshots(): void
    {
        $parser = new LiteParse(new Config(
            extractScreenshots: true,
            detectScreenshotRects: true,
            quiet: true,
        ));
        $screenshots = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf')->screenshots();

        $this->assertNotEmpty($screenshots);
        foreach ($screenshots as $screenshot) {
            $this->assertFalse($screenshot->isSolidFill);
            $this->assertNotEmpty($screenshot->rects);
            $this->assertInstanceOf(ScreenshotRect::class, $screenshot->rects[0]);
        }
    }

    public function test_screenshot_rects_are_empty_without_detect_screenshot_rects(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $screenshots = $parser->screenshotFile($this->fixturesDir.'/pdf-headings-images-tables.pdf', [1]);

        $this->assertSame([], $screenshots[0]->rects);
    }

    public function test_page_orientation_corrections_is_accepted_without_error(): void
    {
        $parser = new LiteParse(new Config(
            pageOrientationCorrections: [['page' => 1, 'angle' => 90]],
            quiet: true,
        ));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $this->assertSame(2, $result->pageCount());
    }
}
