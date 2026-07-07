use std::ffi::c_char;

use liteparse::output::{markdown, text};

use crate::error::set_last_error;
use crate::ffi::handles::{ResultHandle, result_drop, result_ref};
use crate::ffi::strings::string_to_owned_c_char;

/// Render the parsed document as pretty-printed JSON. Deliberately does
/// *not* go through `liteparse::output::json::format_json` — that formatter
/// backs the `lit` CLI's `--format json` and intentionally drops most
/// `TextItem` fields (font size, fill/stroke color, rotation, links,
/// strikethrough, ...) down to a lean `{text, x, y, width, height,
/// font_name, font_size, confidence}` shape. `ParsedPage` and `TextItem`
/// already derive `Serialize` directly (with internal-only fields marked
/// `#[serde(skip)]`), so serializing `data.result.pages` as-is gives the PHP
/// side every field liteparse extracts per text item, at no extra cost.
/// Returns NULL on error (rare — JSON formatting of already-parsed data does
/// not normally fail). Free the result with `liteparse_string_free`.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_json(handle: *const ResultHandle) -> *mut c_char {
    let data = unsafe { result_ref(handle) };
    match serde_json::to_string_pretty(&serde_json::json!({ "pages": &data.result.pages })) {
        Ok(s) => string_to_owned_c_char(s),
        Err(e) => {
            set_last_error(format!("failed to format JSON: {e}"));
            std::ptr::null_mut()
        }
    }
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
/// `ProjectedLine` already derives `Serialize` and is a public field on
/// `ParsedPage` — `#[serde(skip)]` there only suppresses it from
/// `ParsedPage`'s own derive, it doesn't stop us serializing it directly, same
/// bypass as `liteparse_result_json`. Returns NULL on error. Free the result
/// with `liteparse_string_free`.
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
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_markdown(handle: *const ResultHandle) -> *mut c_char {
    let data = unsafe { result_ref(handle) };
    string_to_owned_c_char(markdown::format_markdown(
        &data.result.pages,
        &data.result.outline,
        data.image_mode,
    ))
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

/// # Safety
/// `handle` must be null or a value previously returned by a
/// `liteparse_parser_parse_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_result_free(handle: *mut ResultHandle) {
    unsafe { result_drop(handle) };
}
