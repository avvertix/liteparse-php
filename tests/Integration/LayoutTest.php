<?php

declare(strict_types=1);

namespace LiteParse\Tests\Integration;

use LiteParse\Config;
use LiteParse\ExtractedImage;
use LiteParse\Layout\Block;
use LiteParse\Layout\BlockKind;
use LiteParse\LiteParse;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__).'/fixtures';
    }

    public function test_blocks_are_grouped_by_page_and_tagged_with_page_number(): void
    {
        $parser = new LiteParse(new Config(extractBlocks: true, quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $pages = $result->blocks();

        $this->assertCount(2, $pages);
        foreach ($pages as $page) {
            $this->assertNotEmpty($page['blocks']);
            foreach ($page['blocks'] as $block) {
                $this->assertInstanceOf(Block::class, $block);
                $this->assertSame($page['page_number'], $block->pageNumber);
            }
        }

        $kinds = array_map(fn (Block $b) => $b->kind, $pages[0]['blocks']);
        $this->assertContains(BlockKind::Heading, $kinds);
    }

    public function test_flat_blocks_matches_grouped_blocks_in_reading_order(): void
    {
        $parser = new LiteParse(new Config(extractBlocks: true, quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $grouped = $result->blocks();
        $flat = $result->flatBlocks();

        $groupedTotal = array_sum(array_map(fn (array $page) => count($page['blocks']), $grouped));
        $this->assertCount($groupedTotal, $flat);

        $expectedPageNumbers = array_merge([], ...array_map(
            fn (array $page) => array_fill(0, count($page['blocks']), $page['page_number']),
            $grouped,
        ));
        $this->assertSame($expectedPageNumbers, array_map(fn (Block $b) => $b->pageNumber, $flat));
    }

    public function test_blocks_are_empty_without_extract_blocks(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        foreach ($result->blocks() as $page) {
            $this->assertSame([], $page['blocks']);
        }
        $this->assertSame([], $result->flatBlocks());
    }

    public function test_images_returns_metadata_without_bytes_and_joins_against_figure_blocks(): void
    {
        $parser = new LiteParse(new Config(extractBlocks: true, extractImages: true, quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $images = $result->images();
        $this->assertNotEmpty($images);

        $byId = [];
        foreach ($images as $image) {
            $this->assertInstanceOf(ExtractedImage::class, $image);
            $byId[$image->id] = $image;
        }

        $figureBlocks = array_filter($result->flatBlocks(), fn (Block $b) => $b->kind === BlockKind::Figure);
        $this->assertNotEmpty($figureBlocks);
        foreach ($figureBlocks as $block) {
            $this->assertArrayHasKey($block->id, $byId);
            $this->assertSame($byId[$block->id]->format, $block->format);
        }
    }

    public function test_images_is_empty_without_extract_images(): void
    {
        $parser = new LiteParse(new Config(quiet: true));
        $result = $parser->parseFile($this->fixturesDir.'/pdf-headings-images-tables.pdf');

        $this->assertSame([], $result->images());
    }
}
