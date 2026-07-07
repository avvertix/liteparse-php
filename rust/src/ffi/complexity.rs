use std::ffi::c_char;
use std::slice;

use liteparse::types::PdfInput;

use crate::error::set_last_error;
use crate::ffi::handles::{ParserData, ParserHandle, parser_ref};
use crate::ffi::strings::{c_char_to_string, string_to_owned_c_char};
use crate::runtime::block_on;

/// Cheap per-page complexity pre-check (no OCR, no rendering) — returns a
/// JSON array of `PageComplexityStats`, one entry per page. Useful for
/// deciding whether a document needs OCR before committing to a full parse.
/// Returns NULL and sets the last error on failure.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by
/// `liteparse_parser_new`. `path` must be a valid NUL-terminated C string.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_is_complex_file(
    handle: *const ParserHandle,
    path: *const c_char,
) -> *mut c_char {
    let Some(path) = (unsafe { c_char_to_string(path) }) else {
        set_last_error("path must not be null");
        return std::ptr::null_mut();
    };

    let data = unsafe { parser_ref(handle) };
    is_complex_and_wrap(data, PdfInput::Path(path))
}

/// Same as `liteparse_parser_is_complex_file`, from in-memory bytes.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by
/// `liteparse_parser_new`. `data` must point to at least `len` readable
/// bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_is_complex_bytes(
    handle: *const ParserHandle,
    data: *const u8,
    len: usize,
) -> *mut c_char {
    if data.is_null() && len != 0 {
        set_last_error("data must not be null when len > 0");
        return std::ptr::null_mut();
    }
    let bytes = if len == 0 {
        Vec::new()
    } else {
        unsafe { slice::from_raw_parts(data, len) }.to_vec()
    };

    let parser_data = unsafe { parser_ref(handle) };
    is_complex_and_wrap(parser_data, PdfInput::Bytes(bytes))
}

fn is_complex_and_wrap(data: &ParserData, input: PdfInput) -> *mut c_char {
    match block_on(data.parser.is_complex(input)) {
        Ok(stats) => match serde_json::to_string(&stats) {
            Ok(json) => string_to_owned_c_char(json),
            Err(e) => {
                set_last_error(format!("failed to serialize complexity stats: {e}"));
                std::ptr::null_mut()
            }
        },
        Err(e) => {
            set_last_error(e.to_string());
            std::ptr::null_mut()
        }
    }
}
