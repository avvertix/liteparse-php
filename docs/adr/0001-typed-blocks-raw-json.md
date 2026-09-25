# Typed value objects for structured layout output, raw arrays everywhere else

`ParseResult::blocks()`/`flatBlocks()`/`images()` return typed VOs (`Layout\Block`,
`Layout\TableCell`, `ExtractedImage`), while `json()`, `lines()`, and `search()` keep
returning raw arrays decoded from FFI JSON. This is a deliberate split, not an oversight:
the layout/block layer is the one shape consumers actually rebuild their own document
model from — confirmed by a real integration (`onlytext-cloud`'s `LiteParseProcessor`)
that was parsing `markdown()` back through a CommonMark parser to reconstruct a
`Page`/`Block` tree by hand, losing the bounding boxes `liteparse` had already computed
and relying on a `-----` thematic break standing in for page boundaries. Typing that one
layer gives consumers real property access and static analysis exactly where they need
it, without this package taking on the cost of mirroring every field of `json()`'s large,
frequently-drifting, mostly-pass-through payload in PHP classes.

## Considered options

- Type the whole `json()` payload (`TextItem`, `ProjectedLine`, `DocumentMetadata`, ...).
  Rejected: that shape tracks upstream's `ParsedPage`/`ParseResult` closely and changes
  with every liteparse bump (see `.claude/skills/upgrade-liteparse/SKILL.md`) — a full VO
  layer would mean maintaining a parallel class hierarchy in lockstep with every upstream
  release, for fields most consumers only read a handful of.
- Keep `blocks` as a raw array too, matching everything else. Rejected: it's the shape
  this ADR exists to fix — the Parxy case showed raw-array block data still gets
  re-derived from markdown by hand rather than consumed directly, because there was no
  ergonomic typed entry point pointing at it as the recommended path.

## Consequences

`Layout\Block` is a single flat class discriminated by `BlockKind`, mirroring upstream's
own `LayoutBlock` shape (chosen there for the same enum-can't-carry-data reason this
binding already works around for its other cross-boundary types) rather than a
subclass-per-kind hierarchy.
