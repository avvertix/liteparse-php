<?php

declare(strict_types=1);

namespace LiteParse\Tests\Integration;

use LiteParse\Config;
use LiteParse\Exception\LiteParseException;
use LiteParse\LiteParse;
use LiteParse\OutputFormat;
use PHPUnit\Framework\TestCase;

final class LiteParseTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__).'/fixtures';
    }

    public function test_parse_file_extracts_text(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $this->assertSame(2, $result->pageCount());
        $text = $result->text();
        $this->assertStringContainsString('Introduction', $text);
        $this->assertStringContainsString('Section heading', $text);
    }

    public function test_parse_bytes_matches_parse_file(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $bytes = file_get_contents($this->fixturesDir.'/pdf-simple.pdf');
        $result = $parser->parseBytes($bytes);

        $this->assertSame(1, $result->pageCount());
        $this->assertStringContainsString('This is a heading 1', $result->text());
    }

    public function test_json_output_is_structured(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $json = $result->json();
        $this->assertArrayHasKey('pages', $json);
        $this->assertCount(2, $json['pages']);
        $this->assertArrayHasKey('text_items', $json['pages'][0]);
    }

    public function test_lines_output_includes_projected_lines_with_bbox_and_spans(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $lines = $result->lines()['pages'][0]['projected_lines'];
        $this->assertNotEmpty($lines, 'expected at least one projected line on page 1');

        $line = $lines[0];
        foreach (['text', 'bbox', 'anchor', 'dominant_font_size', 'region_path', 'spans'] as $key) {
            $this->assertArrayHasKey($key, $line);
        }
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $this->assertArrayHasKey($key, $line['bbox']);
        }
        $this->assertNotEmpty($line['spans'], 'expected the line to carry its source TextItem(s)');
        $this->assertArrayHasKey('text', $line['spans'][0]);
    }

    public function test_json_text_items_include_font_size_and_color_with_bounding_box(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-simple.pdf');

        $items = $result->json()['pages'][0]['text_items'];
        $withColor = array_values(array_filter(
            $items,
            static fn (array $item) => isset($item['fill_color']) && $item['fill_color'] !== 'ff000000'
        ));

        $this->assertNotEmpty($withColor, 'expected at least one native-text item to carry a non-black fill_color');

        $item = $withColor[0];
        foreach (['text', 'x', 'y', 'width', 'height', 'font_size', 'fill_color'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $item['fill_color']);
        $this->assertIsFloat($item['font_size']);
    }

    public function test_markdown_output_is_non_empty(): void
    {
        $parser = new LiteParse(new Config(quiet: true, outputFormat: OutputFormat::Markdown));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $markdown = $result->markdown();
        $this->assertNotSame('', trim($markdown));
        $this->assertStringContainsString('# Introduction', $markdown);
        // The document's table (Shape/Volume/Parameters) should reconstruct
        // as a markdown pipe table.
        $this->assertStringContainsString('|', $markdown);
    }

    public function test_parse_file_with_embedded_attachment_extracts_text(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-with-attachment.pdf');

        $this->assertSame(1, $result->pageCount());
        $this->assertStringContainsString('embedded file', $result->text());
    }

    public function test_parse_file_with_no_content_returns_empty_result(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-with-no-content.pdf');

        $this->assertSame(1, $result->pageCount());
        $this->assertSame([], $result->json()['pages'][0]['text_items']);
    }

    public function test_parse_file_throws_on_missing_file(): void
    {
        $parser = new LiteParse(new Config(quiet: true));

        $this->expectException(LiteParseException::class);
        $parser->parseFile($this->fixturesDir.'/does-not-exist.pdf');
    }
}
