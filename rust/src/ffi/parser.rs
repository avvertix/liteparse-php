use std::ffi::c_char;
use std::slice;

use liteparse::types::PdfInput;
use liteparse::{LiteParse, LiteParseConfig};

use crate::error::set_last_error;
use crate::ffi::handles::{
    ParserData, ParserHandle, ResultData, ResultHandle, parser_drop, parser_into_handle,
    parser_ref, result_into_handle,
};
use crate::ffi::strings::c_char_to_string;
use crate::runtime::block_on;

/// Create a new parser from a JSON-encoded `LiteParseConfig`. Pass NULL (or
/// `"null"`) to use all defaults. Returns NULL and sets the last error on
/// invalid JSON.
///
/// # Safety
/// `config_json` must be null or a valid NUL-terminated C string.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_new(config_json: *const c_char) -> *mut ParserHandle {
    let config = match unsafe { c_char_to_string(config_json) } {
        None => LiteParseConfig::default(),
        Some(json) => match serde_json::from_str::<LiteParseConfig>(&json) {
            Ok(cfg) => cfg,
            Err(e) => {
                set_last_error(format!("invalid config JSON: {e}"));
                return std::ptr::null_mut();
            }
        },
    };

    parser_into_handle(ParserData {
        parser: LiteParse::new(config),
    })
}

/// # Safety
/// `handle` must be null or a value previously returned by
/// `liteparse_parser_new` and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_free(handle: *mut ParserHandle) {
    unsafe { parser_drop(handle) };
}

/// Parse a document from a file path. Returns NULL and sets the last error
/// on failure.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by
/// `liteparse_parser_new`. `path` must be a valid NUL-terminated C string.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_parse_file(
    handle: *const ParserHandle,
    path: *const c_char,
) -> *mut ResultHandle {
    let Some(path) = (unsafe { c_char_to_string(path) }) else {
        set_last_error("path must not be null");
        return std::ptr::null_mut();
    };

    let data = unsafe { parser_ref(handle) };
    parse_and_wrap(data, PdfInput::Path(path))
}

/// Parse a document from raw in-memory bytes (e.g. a PDF buffer). Returns
/// NULL and sets the last error on failure.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by
/// `liteparse_parser_new`. `data` must point to at least `len` readable
/// bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_parse_bytes(
    handle: *const ParserHandle,
    data: *const u8,
    len: usize,
) -> *mut ResultHandle {
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
    parse_and_wrap(parser_data, PdfInput::Bytes(bytes))
}

fn parse_and_wrap(data: &ParserData, input: PdfInput) -> *mut ResultHandle {
    let image_mode = data.parser.config().image_mode;
    match block_on(data.parser.parse_input(input)) {
        Ok(result) => result_into_handle(ResultData { result, image_mode }),
        Err(e) => {
            set_last_error(e.to_string());
            std::ptr::null_mut()
        }
    }
}
