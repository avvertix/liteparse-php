use std::ffi::c_char;

use liteparse::output::text;
use liteparse::stages;

use crate::error::set_last_error;
use crate::ffi::handles::{
    ResultHandle, ScreenshotListData, ScreenshotListHandle, result_drop, result_ref,
    screenshot_list_into_handle,
};
use crate::ffi::strings::string_to_owned_c_char;

/// Render the parsed document as pretty-printed JSON. Deliberately does
/// *not* go through `liteparse::output::json::format_json` — that formatter
/// backs the `lit` CLI's `--format json` and intentionally drops most
/// `TextItem` fields (font size, fill/stroke color, rotation, links,
/// strikethrough, ...) down to a lean `{text, x, y, width, height,
/// font_name, font_size, confidence}` shape, and we want the richer one.
///
/// Builds its own per-page view (`page_to_json`) rather than serializing
/// `ParsedPage` as-is. `ParsedPage` carries several fields liteparse
/// considers internal (`projected_lines`, `regions`, `graphics`, `figures`,
/// `struct_nodes`, `image_refs`, ...) that used to be unconditionally
/// `#[serde(skip)]`; as of the 2.14.7 upgrade several of those switched to
/// `skip_serializing_if`, meaning a naive `&data.result.pages` serialize
/// would have started silently leaking them into every `json()` response
/// (upstream's own `format_json` is unaffected — it already builds its own
/// view struct field-by-field, same idea as here). Allowlisting fields here
/// means any future "internal field no longer skipped" upstream change is
/// inert for us instead of a silent payload/output-shape regression: a new
/// field only reaches PHP once someone deliberately adds it below.
///
/// Also includes the `ParseResult`-level fields that don't live on a page:
/// `total_pages` (source page count before `max_pages`/`target_pages`
/// truncation), `doc_meta` (present when `extract_document_metadata` is on,
/// `null` otherwise), `page_errors` (populated when `continue_on_page_error`
/// is on, empty otherwise), `images` (populated when `extract_images` is
/// on, empty otherwise — `ExtractedImage.bytes` is `#[serde(skip)]` upstream,
/// so this is metadata only: `id`/`format` match the `img_{id}.{format}`
/// reference `markdown()` and `blocks` emit, `path` is where the bytes were
/// written when `image_output_dir` is set), `xfa_packets` (`null` unless
/// `extract_xfa_packets` is on; `Some([])` for a non-XFA document, `Some`
/// with entries for one), and `creator`/`producer` (the PDF `/Info` dict's
/// entries — always present when the source document has them, independent
/// of every other flag here; distinct from `doc_meta`, which does not carry
/// them). Page screenshots are deliberately not folded in here — PNG bytes
/// would have to go through base64 — see `liteparse_result_screenshots`
/// instead.
///
/// Returns NULL on error (rare — JSON formatting of already-parsed data does
/// not normally fail). Free the result with `liteparse_string_free`.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_json(handle: *const ResultHandle) -> *mut c_char {
    let data = unsafe { result_ref(handle) };
    let pages: Vec<serde_json::Value> = data.result.pages.iter().map(page_to_json).collect();
    match serde_json::to_string_pretty(&serde_json::json!({
        "pages": pages,
        "total_pages": data.result.total_pages,
        "doc_meta": &data.result.doc_meta,
        "page_errors": &data.result.page_errors,
        "images": &data.result.images,
        "xfa_packets": &data.result.xfa_packets,
        "creator": &data.result.creator,
        "producer": &data.result.producer,
    })) {
        Ok(s) => string_to_owned_c_char(s),
        Err(e) => {
            set_last_error(format!("failed to format JSON: {e}"));
            std::ptr::null_mut()
        }
    }
}

/// The public per-page shape for `liteparse_result_json`, documented in
/// `ParseResult::json()`'s PHP docblock — keep the two in sync. Every
/// optional field is omitted (not emitted as `null`) when absent, matching
/// `ParsedPage`'s own `skip_serializing_if` behavior for the fields it
/// mirrors, so existing consumers see the same shape across upgrades
/// regardless of what liteparse adds to `ParsedPage` itself.
fn page_to_json(page: &liteparse::ParsedPage) -> serde_json::Value {
    let mut obj = serde_json::Map::new();
    obj.insert("page_number".into(), serde_json::json!(page.page_number));
    if let Some(label) = &page.page_label {
        obj.insert("page_label".into(), serde_json::json!(label));
    }
    obj.insert("page_width".into(), serde_json::json!(page.page_width));
    obj.insert("page_height".into(), serde_json::json!(page.page_height));
    if let Some(bounds) = &page.content_bounds {
        obj.insert("content_bounds".into(), serde_json::json!(bounds));
    }
    obj.insert("text".into(), serde_json::json!(&page.text));
    if !page.markdown.is_empty() {
        obj.insert("markdown".into(), serde_json::json!(&page.markdown));
    }
    obj.insert("text_items".into(), serde_json::json!(&page.text_items));
    if let Some(vector_graphics) = &page.vector_graphics {
        obj.insert("vector_graphics".into(), serde_json::json!(vector_graphics));
    }
    if let Some(complexity) = &page.complexity {
        obj.insert("complexity".into(), serde_json::json!(complexity));
    }
    if let Some(annotations) = &page.annotations {
        obj.insert("annotations".into(), serde_json::json!(annotations));
    }
    if let Some(form_fields) = &page.form_fields {
        obj.insert("form_fields".into(), serde_json::json!(form_fields));
    }
    if let Some(structure_tree) = &page.structure_tree {
        obj.insert("structure_tree".into(), serde_json::json!(structure_tree));
    }
    if let Some(blocks) = &page.blocks {
        obj.insert("blocks".into(), serde_json::json!(blocks));
    }
    serde_json::Value::Object(obj)
}

/// Render the parsed document's projected lines as pretty-printed JSON — a
/// middle layer between the flat `liteparse_result_json` (`TextItem`s: one per
/// PDFium text run, no grouping) and `liteparse_result_markdown` (fully
/// reconstructed headings/lists/tables, which can lose structure when a
/// table's columns land in separate layout regions). Each line carries its
/// merged bbox, dominant font/style, `region_path` (the xy-cut column/region
/// position used for table and paragraph grouping), and `spans` (the original
/// `TextItem`s that merged into it, so per-run font/color/text survives even
/// where `line.text` concatenates multiple items).
///
/// `ProjectedLine` already derives `Serialize`. `ParsedPage.projected_lines`
/// itself carries `#[serde(skip_serializing_if = "Vec::is_empty")]` (nothing
/// stronger), but that attribute only matters when serializing a `ParsedPage`
/// value directly — accessing `&page.projected_lines` and serializing that
/// `Vec<ProjectedLine>` on its own, as this function does, was never affected
/// by it either way. Returns NULL on error. Free the result with
/// `liteparse_string_free`.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_lines_json(handle: *const ResultHandle) -> *mut c_char {
    let data = unsafe { result_ref(handle) };
    let pages: Vec<serde_json::Value> = data
        .result
        .pages
        .iter()
        .map(|page| {
            serde_json::json!({
                "page_number": page.page_number,
                "page_width": page.page_width,
                "page_height": page.page_height,
                "projected_lines": &page.projected_lines,
            })
        })
        .collect();
    match serde_json::to_string_pretty(&serde_json::json!({ "pages": pages })) {
        Ok(s) => string_to_owned_c_char(s),
        Err(e) => {
            set_last_error(format!("failed to format JSON: {e}"));
            std::ptr::null_mut()
        }
    }
}

/// Render the parsed document as plain text with `--- Page N ---` headers.
/// Free the result with `liteparse_string_free`.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_text(handle: *const ResultHandle) -> *mut c_char {
    let data = unsafe { result_ref(handle) };
    string_to_owned_c_char(text::format_text(&data.result.pages))
}

/// Render the parsed document as Markdown, reconstructing headings, lists,
/// tables and figure references from the spatial layout. Free the result
/// with `liteparse_string_free`.
///
/// liteparse 2.14.7 removed the single-call `output::markdown::format_markdown`
/// this used to delegate to, in favor of composable per-page stage functions
/// (`stages::document_signals`/`extract_blocks`/`render_page_markdown`) —
/// `parse()` itself now only runs them when `output_format == Markdown`, so
/// there is no longer a document-level convenience wrapper to call after the
/// fact. This reconstructs it here so `markdown()` keeps working regardless
/// of what `output_format` the parser was configured with, same as before.
///
/// This also fixes a latent bug: the old `format_markdown(pages, outline,
/// image_mode)` call this replaced took no `keep_headers_footers` parameter
/// at all — it always rendered with chrome suppression on, silently ignoring
/// `Config::$keepHeadersFooters` — because the config-aware call was a
/// different, four-argument function (`format_markdown_pages`) this never
/// called. `keep_headers_footers` is threaded through correctly now, cached
/// on `ResultData` at parse time alongside `image_mode` for the same reason.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_markdown(handle: *const ResultHandle) -> *mut c_char {
    let data = unsafe { result_ref(handle) };
    let signals = stages::document_signals(&data.result.pages, data.keep_headers_footers);
    let options = stages::BlockOptions {
        outline: &data.result.outline,
        image_mode: data.image_mode,
        keep_headers_footers: data.keep_headers_footers,
    };
    let page_md: Vec<String> = data
        .result
        .pages
        .iter()
        .map(|page| {
            let blocks = stages::extract_blocks(page, &signals, &options);
            stages::render_page_markdown(page, blocks.as_deref())
        })
        .collect();
    string_to_owned_c_char(page_md.join("\n\n-----\n\n"))
}

/// Number of pages in the parsed document.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_page_count(handle: *const ResultHandle) -> usize {
    let data = unsafe { result_ref(handle) };
    data.result.pages.len()
}

/// This result's rendered page screenshots, populated only when the parser
/// was configured with `extract_screenshots` (empty otherwise). Reuses the
/// same `ScreenshotListHandle` accessors (`liteparse_screenshot_list_len`,
/// `liteparse_screenshot_bytes`, ...) as the standalone
/// `liteparse_parser_screenshot_*` calls. Free the returned list with
/// `liteparse_screenshot_list_free`.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_screenshots(
    handle: *const ResultHandle,
) -> *mut ScreenshotListHandle {
    let data = unsafe { result_ref(handle) };
    screenshot_list_into_handle(ScreenshotListData {
        items: data.result.screenshots.clone(),
    })
}

/// # Safety
/// `handle` must be null or a value previously returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_free(handle: *mut ResultHandle) {
    unsafe { result_drop(handle) };
}
