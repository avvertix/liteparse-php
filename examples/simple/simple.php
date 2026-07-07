<?php

/**
 * Parse a PDF and print its Markdown reconstruction plus a couple of
 * complexity/search checks.
 *
 * Usage:
 *   php examples/simple/simple.php
 */

require __DIR__.'/../common.php';

use LiteParse\Config;
use LiteParse\LiteParse;
use LiteParse\OutputFormat;

$outputDir = __DIR__.'/output';

$parser = new LiteParse(new Config(outputFormat: OutputFormat::Markdown));

$pdfPath = "{$fixturesDir}/pdf-headings-images-tables.pdf";

// Cheap pre-check: does this document need OCR? (It doesn't — it's a native-text PDF.)
$stats = $parser->isComplexFile($pdfPath);
$pagesNeedingOcr = array_filter($stats, static fn (array $page) => $page['needs_ocr']);
echo count($pagesNeedingOcr).' of '.count($stats)." pages would need OCR.\n";

$result = $parser->parseFile($pdfPath);
echo 'Parsed '.$result->pageCount()." pages.\n";

$matches = $result->search('lorem ipsum');
echo 'Found "lorem ipsum" on page '.($matches[0]['page_number'] ?? '?')."\n";

save_to_output($result->markdown(), 'pdf-headings-images-tables.md', $outputDir);
