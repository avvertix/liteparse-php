---
name: upgrade-liteparse
description: Playbook for bumping this binding's liteparse dependency pin — diffing the current pin against a newer upstream liteparse checkout, auditing for breaking config/API changes, and wiring new features into the PHP binding. Use when asked to upgrade, prepare for, or investigate a liteparse version bump, or to compare what changed in a newly-cloned liteparse checkout.
---

# Upgrade liteparse

Read `HANDOFF.md` (repo root) first — current pin, what's wired, the one open bug to
re-check every time. Read `adaptations.md` (repo root) for the full rationale behind
any past decision this playbook references; append to it, never replace it.

`rust/Cargo.toml` pins `liteparse` from crates.io by version — a real third-party
dependency, not a local path. The user typically clones upstream
`https://github.com/run-llama/liteparse` as a sibling checkout (commonly `../liteparse`
relative to this repo) to diff against; confirm its path if not given.

## 1. Scope the diff to commits that can affect this crate

Upstream tags every sub-crate release (`crates-vX.Y.Z`, `node-vX.Y.Z`, `python-vX.Y.Z`,
`wasm-vX.Y.Z`, `docker-vX.Y.Z`) from one shared history. Only `crates-v*` tags bound this
binding's concern. Scope both the tag range and the path:

```
git log --oneline crates-v<old>..crates-v<new> -- crates/liteparse/src
```

The history is noisy — long runs of one-word `wip` commits from feature branches sit
beside the real signal. Read every `feat:`/`fix:` commit message; skip bare `wip`.

## 2. Build before reading diffs — then check for silent output-shape changes

Bump the pin in `rust/Cargo.toml`, then `cargo build --release` in `rust/` immediately —
before manually tracing what each commit changed. The compiler enumerates every real
breaking type/API change as an error; this is more reliable and far cheaper than reading
dozens of commits by hand. Zero errors means the crate's public surface this binding
actually touches is unaffected, whatever else moved upstream. Read commit diffs to
understand *new capability*, not to hunt for breakage the compiler already ruled out.

Zero errors is necessary but not sufficient: a compile-clean build can still change what
PHP actually receives, silently. Two real incidents from the 2.14.3 → 2.14.7 bump (full
detail in `adaptations.md`):

- **A top-level convenience function this binding called directly was removed outright**
  (`output::markdown::format_markdown` → composable `stages::*` functions) — this one
  *did* fail to compile, but only because we happened to call it by name; a function
  upstream instead *changed behavior in place* would not. Grep `rust/src/ffi/*.rs` for
  every `liteparse::`/`stages::` call site and re-read what each one now does, not just
  whether it still resolves.
- **Fields on a type we serialize get a weaker `#[serde]` attribute** — `#[serde(skip)]`
  loosening to `skip_serializing_if` on several `ParsedPage` fields liteparse considers
  internal (`projected_lines`, `regions`, `graphics`, `figures`, `struct_nodes`,
  `image_refs`) made them start appearing in output that serializes the struct wholesale.
  Upstream's own `output/json.rs` was unaffected (it already builds its own lean view
  struct field-by-field) — only this binding's choice to serialize `ParsedPage` directly
  was exposed. Diff `crates/liteparse/src/types.rs`'s `ParsedPage`/`Page` field attributes
  old vs. new; any `#[serde(skip)]` that weakened is a silent regression, not an error.

## 3. Audit `LiteParseConfig` for fields with no default

`Config.php`'s `toJson()` sends a fixed field set, not every field `LiteParseConfig` has.
A field with no `#[serde(default)]` (or `#[serde(default = "fn")]`) is **mandatory**:
omitting it makes `liteparse_parser_new` throw `missing field '<name>'` — this has broken
the binding across a version bump before (six fields went from optional to mandatory
between 2.4.0 and 2.11.1). Diff `crates/liteparse/src/config.rs` field-by-field against
`Config.php`'s current field list and add any newly-mandatory field, even one you have no
other reason to wire — a `new Config()` with all defaults must keep constructing.
Fields that do carry `#[serde(default...)]` are safe to skip for now.

## 4. Verify classification behaviorally against the fixture

Never trust a `fix:` commit message alone for markdown/table/heading classification —
verify against `tests/fixtures/pdf-headings-images-tables.pdf` (committed for exactly
this). As of the 2.14.3 pass: a table's header row can still land as a separate block
outside the `Table`/`blocks` output rather than inside it, despite three upstream commits
explicitly aimed at that bug (see `adaptations.md`, "2.11.1 → 2.14.3"). Re-check this
exact case on every future bump — don't mark it fixed without a fresh, direct
observation on this fixture, not a different or personal document.

The same discipline applies to any *new* field's shape, not just classification bugs.
Wiring the 2026-09-24 "remaining gaps" batch (see `adaptations.md`), a struct name found
by grepping `types.rs` (`StructNode`) turned out to back a different, deliberately-internal
field (`struct_nodes`) than the one actually being wired (`structure_tree`, really
`Option<StructureTree>` — a recursive tree, nothing like `StructNode`'s flat shape). Caught
immediately because a test asserted against real fixture output instead of the assumed
shape and failed. Grepping a struct name upstream is a hypothesis about a new field's
shape, not a fact — run the fixture and read the actual JSON before writing the docblock.

## 5. Wire only the fields asked for — pick the right pattern

Three established patterns, by where the field lives and what it holds:

- **Allowlisted page field** — `liteparse_result_json` builds each page through the
  explicit `page_to_json` allowlist in `rust/src/ffi/result.rs` (deliberately *not* a
  wholesale `&data.result.pages` serialize — see step 2's second incident for why). A new
  `ParsedPage` field needs one `if let Some(x) = &page.field { obj.insert(...) }` line
  there, plus the matching config flag in `Config.php`. (`complexity`, `blocks`,
  `vector_graphics`, `content_bounds`, `annotations`, `form_fields`, `structure_tree` all
  go through this allowlist — check it directly for the current field list rather than
  trusting this sentence to stay exhaustive.)
- **Envelope field** — a new scalar/struct field on `ParseResult` itself (not a page) has
  no accessor by default; only `.pages` is serialized. Fold it into
  `liteparse_result_json`'s top-level JSON object as a sibling of `"pages"`
  (`rust/src/ffi/result.rs`). Used for `total_pages`, `doc_meta`, `page_errors`.

`src/LiteParse/Layout/BlockKind.php` is a PHP backed enum mirroring the exact set of `kind`
string literals upstream's `LayoutBlock` can emit (`heading`, `paragraph`, `list_item`,
`code`, `table`, `merged_table`, `grid_fallback`, `rule`, `figure` — confirmed exhaustive by
grepping `LayoutBlock::of("...")` call sites in `crates/liteparse/src/layout.rs`, not by
reading a docblock). `Block::fromArray()` calls `BlockKind::from($data['kind'])`, which
throws a `\ValueError` on any kind string with no matching case — the correct, fail-loud
behavior if it ever happens, not a bug to fix defensively. But it means **a new block kind
added upstream is a runtime exception for every `blocks()`/`flatBlocks()` caller**, not a
compile error like a Rust-side breaking change would be. Re-run the same grep every bump and
add any new case to `BlockKind` before it ships, the same way step 3 audits `config.rs`
field-by-field.
- **Handle + accessor** — binary or large payloads (PNG bytes, anything you would not
  base64 through JSON) get their own opaque handle and by-index C accessors, mirroring
  `ScreenshotListHandle` in `rust/src/ffi/handles.rs` / `rust/src/ffi/screenshot.rs`. Used
  for `ParseResult.screenshots` via the new `liteparse_result_screenshots`.

After any Rust FFI change: `bash scripts/build.sh release` to stage the library (cbindgen
regenerates `include/liteparse_php.h` during `cargo build`), then add the new
`liteparse_*` symbol to `LiteParseFfi.php`'s `@method` docblock list.

## 6. Sync PHP + docs in the same change

These three drift silently if not updated together — nothing generates one from another:

- `src/LiteParse/Config.php` — constructor param, `@param` docblock, `toJson()` entry.
- `src/LiteParse/ParseResult.php` — `json()`'s return-type docblock shape (or a new
  method, for a handle+accessor field).
- `README.md`'s config table and the prose above it.

## 7. Verify, then update the two living docs

`composer test` and `composer lint` must stay clean. Then, in the same change:

- Update `HANDOFF.md`'s current-pin line and wired-flags list.
- Append a dated section to `adaptations.md` — what changed upstream, what you verified
  and how (exact commands/fixture), what's still open. Do not delete or rewrite earlier
  sections; they are the record of *why* past decisions were made.
