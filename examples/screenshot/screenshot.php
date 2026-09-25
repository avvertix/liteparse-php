<?php

/**
 * Render page screenshots and demonstrate the screenshot-specific features:
 * blank-page detection (`Screenshot::$isSolidFill`, always computed) and
 * solid-rectangle/line detection (`Screenshot::$rects`, via
 * `Config::$detectScreenshotRects`) — plus screenshots reused from a parse
 * that already happened instead of a second render pass.
 *
 * Requires ext-gd, for the rects overlay only.
 *
 * Usage:
 *   php examples/screenshot/screenshot.php
 */

require __DIR__.'/../common.php';

use LiteParse\Config;
use LiteParse\LiteParse;
use LiteParse\Screenshot;

$outputDir = __DIR__.'/output';

// 1. Basic screenshot rendering. isSolidFill needs no config — it's always computed.
$parser = new LiteParse(new Config);
$screenshots = $parser->screenshotFile("{$fixturesDir}/pdf-headings-images-tables.pdf", pageNumbers: [1, 2]);

foreach ($screenshots as $screenshot) {
    save_to_output($screenshot->bytes, "page-{$screenshot->pageNumber}.png", $outputDir);
    echo "  {$screenshot->width}x{$screenshot->height}px, isSolidFill=".($screenshot->isSolidFill ? 'true' : 'false')."\n";
}

// 2. Screenshots from a parse that already happened: Config::$extractScreenshots avoids
//    rendering the document a second time when you need both text and images.
$parserWithParse = new LiteParse(new Config(extractScreenshots: true));
$result = $parserWithParse->parseFile("{$fixturesDir}/pdf-headings-images-tables.pdf");
echo "\nScreenshots from the parse itself, no second render: ".count($result->screenshots())."\n";

// 3. Config::$detectScreenshotRects — solid rectangles/lines detected on the raster (useful
//    for finding table/form structure on scanned pages with no vector paths). Drawn as an
//    overlay here so the detection is visible, not just counted.
$dpi = 150.0;
$parserRects = new LiteParse(new Config(detectScreenshotRects: true, dpi: $dpi));
$rectShots = $parserRects->screenshotFile("{$fixturesDir}/pdf-headings-images-tables.pdf", pageNumbers: [1, 2]);

echo "\n";
foreach ($rectShots as $screenshot) {
    $lines = array_filter($screenshot->rects, fn ($r) => $r->isLine);
    $boxes = array_filter($screenshot->rects, fn ($r) => ! $r->isLine);
    printf(
        "page %d: %d rects detected (%d lines, %d filled areas)\n",
        $screenshot->pageNumber,
        count($screenshot->rects),
        count($lines),
        count($boxes),
    );

    save_to_output(
        draw_rects_overlay($screenshot, $dpi),
        "page-{$screenshot->pageNumber}-rects.png",
        $outputDir
    );
}

/**
 * Draw every detected rect over the screenshot: blue for lines, red for filled areas, both
 * semi-transparent so a dense page of detections stays legible instead of solid color.
 *
 * `ScreenshotRect` coordinates are in the same 72-DPI point space as `TextItem`/`bbox`
 * everywhere else in this package, not the screenshot's own pixel space — scale by
 * `$dpi / 72` (the same DPI the screenshot itself was rendered at) before drawing.
 */
function draw_rects_overlay(Screenshot $screenshot, float $dpi): string
{
    $scale = $dpi / 72.0;
    $image = imagecreatefromstring($screenshot->bytes);
    imagealphablending($image, true);

    $red = imagecolorallocatealpha($image, 255, 0, 0, 90);
    $blue = imagecolorallocatealpha($image, 0, 90, 255, 40);

    foreach ($screenshot->rects as $rect) {
        imagerectangle(
            $image,
            (int) ($rect->x * $scale),
            (int) ($rect->y * $scale),
            (int) (($rect->x + $rect->width) * $scale),
            (int) (($rect->y + $rect->height) * $scale),
            $rect->isLine ? $blue : $red
        );
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}
