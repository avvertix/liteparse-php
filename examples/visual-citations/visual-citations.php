<?php

/**
 * Visual citations: search parsed text for a phrase, then highlight exactly where each
 * match sits on the rendered page — the pattern for showing an agent's/RAG answer's source
 * instead of just returning text (see liteparse's "Visual Citations" guide).
 *
 * `ParseResult::search()` already merges matches split across multiple `TextItem`s into one
 * bounding box. That box is in the same 72-DPI point space as every other bbox in this
 * package (top-left origin) — scale by `dpi / 72` (the DPI the screenshot itself was
 * rendered at) to land on the right pixels.
 *
 * Requires ext-gd, for drawing the highlight boxes.
 *
 * Usage:
 *   php examples/visual-citations/visual-citations.php
 */

require __DIR__.'/../common.php';

use LiteParse\Config;
use LiteParse\LiteParse;
use LiteParse\Screenshot;

$outputDir = __DIR__.'/output';
$phrase = 'lorem ipsum';
$dpi = 150.0;

// Parse and render at the same DPI — a mismatch here is the #1 way to misplace highlights.
$parser = new LiteParse(new Config(dpi: $dpi));
$result = $parser->parseFile("{$fixturesDir}/pdf-headings-images-tables.pdf");

$matches = $result->search($phrase, caseSensitive: false);
echo count($matches)." match(es) for \"{$phrase}\"\n";

$matchesByPage = [];
foreach ($matches as $match) {
    $matchesByPage[$match['page_number']][] = $match;
}

$screenshots = $parser->screenshotFile(
    "{$fixturesDir}/pdf-headings-images-tables.pdf",
    pageNumbers: array_keys($matchesByPage)
);

foreach ($screenshots as $screenshot) {
    $pageMatches = $matchesByPage[$screenshot->pageNumber];
    printf("page %d: %d match(es)\n", $screenshot->pageNumber, count($pageMatches));

    save_to_output(
        highlight_matches($screenshot, $pageMatches, $dpi),
        "page-{$screenshot->pageNumber}-citations.png",
        $outputDir
    );
}

/**
 * Draw a semi-transparent yellow box over each match, mirroring the highlight produced in
 * liteparse's own visual-citations guide.
 *
 * @param  list<array{x: float, y: float, width: float, height: float}>  $matches
 */
function highlight_matches(Screenshot $screenshot, array $matches, float $dpi): string
{
    $scale = $dpi / 72.0;
    $image = imagecreatefromstring($screenshot->bytes);
    imagealphablending($image, true);

    $yellow = imagecolorallocatealpha($image, 255, 235, 0, 60);

    foreach ($matches as $match) {
        imagefilledrectangle(
            $image,
            (int) ($match['x'] * $scale),
            (int) ($match['y'] * $scale),
            (int) (($match['x'] + $match['width']) * $scale),
            (int) (($match['y'] + $match['height']) * $scale),
            $yellow
        );
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}
