<?php

declare(strict_types=1);

namespace LiteParse\Layout;

/**
 * One cell of a `table`/`merged_table` `Block`, from its `header`/`rows`.
 */
final class TableCell
{
    /**
     * @param  ?array{x: float, y: float, width: float, height: float}  $bbox  Null for cells
     *                                                                         with no ink behind them — padding inserted to square off a ragged grid, or
     *                                                                         halves of a merged run split at an estimated position.
     * @param  ?int  $colspan  Merge span, present only on `merged_table` cells and only when > 1.
     * @param  ?int  $rowspan  Merge span, present only on `merged_table` cells and only when > 1.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $bbox = null,
        public readonly ?int $colspan = null,
        public readonly ?int $rowspan = null,
    ) {}

    /**
     * @param  array{text: string, bbox?: array{x: float, y: float, width: float, height: float}, colspan?: int, rowspan?: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'],
            bbox: $data['bbox'] ?? null,
            colspan: $data['colspan'] ?? null,
            rowspan: $data['rowspan'] ?? null,
        );
    }
}
