<?php

/**
 * Walk a tagged PDF's logical structure tree (`Config::$extractStructureTree`) and print
 * it as an indented outline — headings, paragraphs, lists, and figures in document order,
 * each resolved back to its actual rendered text via `marked_content_ids`.
 *
 * `structure_tree` is a real recursive tree (`{roots: [{type, title?, marked_content_ids,
 * children, ...}, ...]}`), not a flat list — most elements (paragraphs, list items) carry
 * no `title`, only `marked_content_ids` pointing at the `TextItem`s that make up their
 * content. Joining against `text_items`' `mcid` field (present regardless of
 * `Config::$extractTextMetadata`) is how you recover their actual text.
 *
 * Usage:
 *   php examples/structure-tree/structure-tree.php
 */

require __DIR__.'/../common.php';

use LiteParse\Config;
use LiteParse\LiteParse;

$outputDir = __DIR__.'/output';

$parser = new LiteParse(new Config(extractStructureTree: true));
$json = $parser->parseFile("{$fixturesDir}/pdf-headings-images-tables.pdf")->json();

/**
 * Every mcid on this page mapped to its TextItem's text, in document order — the join key
 * between a structure element's `marked_content_ids` and what was actually rendered.
 *
 * @param  list<array{mcid?: int, text: string}>  $textItems
 * @return array<int, string>
 */
function mcid_text_index(array $textItems): array
{
    $index = [];
    foreach ($textItems as $item) {
        if (isset($item['mcid'])) {
            $index[$item['mcid']] = ($index[$item['mcid']] ?? '').$item['text'];
        }
    }

    return $index;
}

/**
 * Every mcid under this element, including its own and every descendant's — a paragraph's
 * text can be split across child `Span`s with their own `marked_content_ids`.
 *
 * @param  array{marked_content_ids: list<int>, children: list<mixed>}  $element
 * @return list<int>
 */
function collect_mcids(array $element): array
{
    $mcids = $element['marked_content_ids'];
    foreach ($element['children'] as $child) {
        $mcids = [...$mcids, ...collect_mcids($child)];
    }

    return $mcids;
}

/**
 * Render one element and its children as indented outline lines. Prefers `title` (set on
 * headings and figures) over resolving text from `marked_content_ids`; truncates long
 * resolved text so the outline stays scannable.
 *
 * @param  array{type: string, title?: string, alt_text?: string, marked_content_ids: list<int>, children: list<mixed>}  $element
 * @param  array<int, string>  $mcidText
 * @return list<string>
 */
function outline_lines(array $element, array $mcidText, int $depth = 0): array
{
    $indent = str_repeat('  ', $depth);
    $label = $element['title'] ?? $element['alt_text'] ?? null;

    if ($label === null) {
        $text = implode('', array_map(
            fn (int $mcid) => $mcidText[$mcid] ?? '',
            array_filter($element['marked_content_ids'], fn ($mcid) => isset($mcidText[$mcid])),
        ));
        $text = trim($text);
        $label = $text === '' ? null : (mb_strlen($text) > 70 ? mb_substr($text, 0, 70).'…' : $text);
    }

    $lines = [$indent.$element['type'].($label !== null ? ": {$label}" : '')];
    foreach ($element['children'] as $child) {
        $lines = [...$lines, ...outline_lines($child, $mcidText, $depth + 1)];
    }

    return $lines;
}

$outlineText = '';
$typeCounts = [];

foreach ($json['pages'] as $page) {
    $mcidText = mcid_text_index($page['text_items']);
    $outlineText .= "--- page {$page['page_number']} ---\n";

    foreach ($page['structure_tree']['roots'] as $root) {
        $outlineText .= implode("\n", outline_lines($root, $mcidText))."\n";

        array_walk_recursive($root, function ($value, $key) use (&$typeCounts) {
            if ($key === 'type') {
                $typeCounts[$value] = ($typeCounts[$value] ?? 0) + 1;
            }
        });
    }
}

save_to_output($outlineText, 'structure-outline.txt', $outputDir);

echo "Element counts:\n";
arsort($typeCounts);
foreach ($typeCounts as $type => $count) {
    echo "  {$type}: {$count}\n";
}
