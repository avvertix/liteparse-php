<?php

/**
 * Render the first two pages of a PDF to PNG screenshots.
 *
 * Usage:
 *   php examples/screenshot/screenshot.php
 */

require __DIR__.'/../common.php';

use LiteParse\Config;
use LiteParse\LiteParse;

$outputDir = __DIR__.'/output';

$parser = new LiteParse(new Config);

$screenshots = $parser->screenshotFile("{$fixturesDir}/pdf-headings-images-tables.pdf", pageNumbers: [1, 2]);

foreach ($screenshots as $screenshot) {
    save_to_output(
        $screenshot->bytes,
        "page-{$screenshot->pageNumber}.png",
        $outputDir
    );
    echo "  {$screenshot->width}x{$screenshot->height}px\n";
}
