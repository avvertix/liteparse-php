<?php

/**
 * Extract text from a plain image (no PDF involved) via an HTTP OCR server.
 *
 * `LiteParse::parseFile()`/`parseBytes()` already accept images (`.jpg`, `.png`, `.gif`,
 * `.bmp`, `.tiff`, `.webp`, `.svg`) directly — they're converted to a one-page PDF natively
 * in Rust (no ImageMagick needed since liteparse 2.8.0). But a bare image carries no
 * embedded text, so that PDF is blank until OCR fills it in: this binding ships without the
 * bundled Tesseract engine, so `Config::$ocrEnabled` needs `Config::$ocrServerUrl` pointed
 * at an HTTP OCR server (see the liteparse OCR guide and `OCR_API_SPEC.md`).
 *
 * Prerequisite — an HTTP OCR server implementing `OCR_API_SPEC.md` reachable at
 * http://localhost:8828/ocr. liteparse ships ready-to-use reference servers (EasyOCR,
 * PaddleOCR, SuryaOCR) at https://github.com/run-llama/liteparse/tree/main/ocr — see its OCR
 * guide for how to build and run one.
 * The first OCR request after a language switch pays a one-time reader-init cost (~30-40s for
 * EasyOCR) — expected, not a hang.
 *
 * Usage:
 *   php examples/ocr/ocr.php
 */

require __DIR__.'/../common.php';

use LiteParse\Config;
use LiteParse\LiteParse;

$outputDir = __DIR__.'/output';
$ocrServerUrl = 'http://localhost:8828/ocr';

// 1. Stand in for "a photographed/scanned page" by rendering a fixture page to PNG — a
//    plain raster with no PDF structure, no embedded text, nothing but pixels.
$renderer = new LiteParse(new Config(dpi: 200.0));
[$screenshot] = $renderer->screenshotFile("{$fixturesDir}/pdf-headings-images-tables.pdf", [1]);
$imagePath = "{$outputDir}/source-page.png";
if (! is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}
file_put_contents($imagePath, $screenshot->bytes);
echo "Saved: ocr/output/source-page.png ({$screenshot->width}x{$screenshot->height})\n\n";

// 2. Parse the image with OCR off (the default) — a bare image has no native text layer,
//    so this comes back empty. This is the mistake to avoid: pointing parseFile() at an
//    image without also enabling OCR silently yields nothing, not an error.
$withoutOcr = new LiteParse(new Config);
$plainResult = $withoutOcr->parseFile($imagePath);
$plainPageText = trim($plainResult->json()['pages'][0]['text']);
echo "Without OCR, page text: ".($plainPageText === '' ? '(empty — no native text layer on a bare image)' : $plainPageText)."\n\n";

// 3. Parse the same image again with OCR pointed at the EasyOCR server. Setting
//    ocrServerUrl alone is enough — it implies ocrEnabled: true as a convenience.
$withOcr = new LiteParse(new Config(
    ocrServerUrl: $ocrServerUrl,
    dpi: 200.0,
));

try {
    $start = microtime(true);
    $ocrResult = $withOcr->parseFile($imagePath);
    $elapsed = microtime(true) - $start;
} catch (\LiteParse\Exception\LiteParseException $e) {
    fwrite(STDERR, "OCR request failed: {$e->getMessage()}\n");
    fwrite(STDERR, "Is the EasyOCR server running? Try: docker compose up -d easyocr\n");
    exit(1);
}

echo "With OCR ({$ocrServerUrl}, took ".round($elapsed, 1)."s):\n";
echo $ocrResult->text()."\n";

save_to_output($ocrResult->text(), 'recovered-text.txt', $outputDir);

// OCR-derived text items carry a `confidence` score and `font_name === 'OCR'` instead of
// real font metadata — useful for filtering out low-confidence reads before trusting the
// text downstream.
$items = $ocrResult->json()['pages'][0]['text_items'];
$avgConfidence = array_sum(array_column($items, 'confidence')) / count($items);
printf("\n%d text items, average confidence %.0f%%\n", count($items), $avgConfidence * 100);
