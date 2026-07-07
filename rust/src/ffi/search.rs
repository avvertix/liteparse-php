use std::ffi::c_char;

use liteparse::{SearchOptions, search_items};

use crate::error::set_last_error;
use crate::ffi::handles::{ResultHandle, result_ref};
use crate::ffi::strings::{c_char_to_string, string_to_owned_c_char};

/// Search a parsed document's text items for phrase matches, returning a
/// JSON array of `{page_number, text, x, y, width, height}` objects (one per
/// match, in document order). Matches that span multiple text items are
/// merged into a single entry with a combined bounding box. Returns NULL and
/// sets the last error on failure (e.g. invalid `phrase` pointer).
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_parse_*` function and not yet freed. `phrase` must be a
/// valid NUL-terminated C string.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_search(
    handle: *const ResultHandle,
    phrase: *const c_char,
    case_sensitive: i32,
) -> *mut c_char {
    let Some(phrase) = (unsafe { c_char_to_string(phrase) }) else {
        set_last_error("phrase must not be null");
        return std::ptr::null_mut();
    };

    let data = unsafe { result_ref(handle) };
    let options = SearchOptions {
        phrase,
        case_sensitive: case_sensitive != 0,
    };

    let mut matches = Vec::new();
    for page in &data.result.pages {
        for item in search_items(&page.text_items, &options) {
            matches.push(serde_json::json!({
                "page_number": page.page_number,
                "text": item.text,
                "x": item.x,
                "y": item.y,
                "width": item.width,
                "height": item.height,
            }));
        }
    }

    match serde_json::to_string(&matches) {
        Ok(json) => string_to_owned_c_char(json),
        Err(e) => {
            set_last_error(format!("failed to serialize search results: {e}"));
            std::ptr::null_mut()
        }
    }
}
