<?php

declare(strict_types=1);

namespace LiteParse\Tests\Integration;

use LiteParse\Config;
use LiteParse\LiteParse;
use LiteParse\Screenshot;
use PHPUnit\Framework\TestCase;

final class FeaturesTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__).'/fixtures';
    }

    public function test_is_complex_file_returns_per_page_stats(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $stats = $parser->isComplexFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $this->assertCount(2, $stats);
        $this->assertArrayHasKey('page_number', $stats[0]);
        $this->assertArrayHasKey('needs_ocr', $stats[0]);
        $this->assertArrayHasKey('reasons', $stats[0]);
    }

    public function test_screenshot_file_renders_requested_pages(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $screenshots = $parser->screenshotFile($this->fixturesDir.'/pdf-headings-images-tables.pdf', [1, 2]);

        $this->assertCount(2, $screenshots);
        foreach ($screenshots as $screenshot) {
            $this->assertInstanceOf(Screenshot::class, $screenshot);
            $this->assertGreaterThan(0, $screenshot->width);
            $this->assertGreaterThan(0, $screenshot->height);
            $this->assertStringStartsWith("\x89PNG", $screenshot->bytes);
        }
        $this->assertSame(1, $screenshots[0]->pageNumber);
        $this->assertSame(2, $screenshots[1]->pageNumber);
    }

    public function test_search_finds_phrase_across_document(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $matches = $result->search('lorem ipsum');

        $this->assertNotEmpty($matches);
        $this->assertSame(1, $matches[0]['page_number']);
        $this->assertArrayHasKey('x', $matches[0]);
        $this->assertArrayHasKey('width', $matches[0]);
        // The phrase recurs on both pages — confirms the search walks the
        // whole document, not just the first page.
        $this->assertContains(2, array_column($matches, 'page_number'));
    }

    public function test_search_with_no_matches_returns_empty_array(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-simple.pdf');

        $this->assertSame([], $result->search('this phrase definitely does not appear anywhere'));
    }
}
