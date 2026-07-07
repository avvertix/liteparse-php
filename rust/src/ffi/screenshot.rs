use std::ffi::c_char;
use std::slice;

use liteparse::types::PdfInput;

use crate::error::set_last_error;
use crate::ffi::handles::{
    ParserData, ParserHandle, ScreenshotListData, ScreenshotListHandle, parser_ref,
    screenshot_list_drop, screenshot_list_into_handle, screenshot_list_ref,
};
use crate::ffi::strings::c_char_to_string;
use crate::runtime::block_on;

/// Render document pages to PNG screenshots. `page_numbers` (1-based) selects
/// which pages to render; pass NULL (or `page_numbers_len == 0`) for all
/// pages. Returns NULL and sets the last error on failure.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by
/// `liteparse_parser_new`. `path` must be a valid NUL-terminated C string.
/// `page_numbers` must be null or point to at least `page_numbers_len`
/// readable `u32`s.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_screenshot_file(
    handle: *const ParserHandle,
    path: *const c_char,
    page_numbers: *const u32,
    page_numbers_len: usize,
) -> *mut ScreenshotListHandle {
    let Some(path) = (unsafe { c_char_to_string(path) }) else {
        set_last_error("path must not be null");
        return std::ptr::null_mut();
    };

    let pages = unsafe { page_numbers_to_vec(page_numbers, page_numbers_len) };
    let data = unsafe { parser_ref(handle) };
    screenshot_and_wrap(data, PdfInput::Path(path), pages)
}

/// Same as `liteparse_parser_screenshot_file`, from in-memory bytes.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by
/// `liteparse_parser_new`. `input_data` must point to at least `input_len`
/// readable bytes. `page_numbers` must be null or point to at least
/// `page_numbers_len` readable `u32`s.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_parser_screenshot_bytes(
    handle: *const ParserHandle,
    input_data: *const u8,
    input_len: usize,
    page_numbers: *const u32,
    page_numbers_len: usize,
) -> *mut ScreenshotListHandle {
    if input_data.is_null() && input_len != 0 {
        set_last_error("data must not be null when len > 0");
        return std::ptr::null_mut();
    }
    let bytes = if input_len == 0 {
        Vec::new()
    } else {
        unsafe { slice::from_raw_parts(input_data, input_len) }.to_vec()
    };

    let pages = unsafe { page_numbers_to_vec(page_numbers, page_numbers_len) };
    let data = unsafe { parser_ref(handle) };
    screenshot_and_wrap(data, PdfInput::Bytes(bytes), pages)
}

unsafe fn page_numbers_to_vec(ptr: *const u32, len: usize) -> Option<Vec<u32>> {
    if ptr.is_null() || len == 0 {
        None
    } else {
        Some(unsafe { slice::from_raw_parts(ptr, len) }.to_vec())
    }
}

fn screenshot_and_wrap(
    data: &ParserData,
    input: PdfInput,
    page_numbers: Option<Vec<u32>>,
) -> *mut ScreenshotListHandle {
    match block_on(data.parser.screenshot_input(input, page_numbers)) {
        Ok(items) => screenshot_list_into_handle(ScreenshotListData { items }),
        Err(e) => {
            set_last_error(e.to_string());
            std::ptr::null_mut()
        }
    }
}

/// Number of screenshots in the list.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_screenshot_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_screenshot_list_len(
    handle: *const ScreenshotListHandle,
) -> usize {
    let data = unsafe { screenshot_list_ref(handle) };
    data.items.len()
}

/// 1-based page number of the screenshot at `idx`, or 0 if out of range.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_screenshot_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_screenshot_page_number(
    handle: *const ScreenshotListHandle,
    idx: usize,
) -> u32 {
    let data = unsafe { screenshot_list_ref(handle) };
    data.items.get(idx).map(|s| s.page_num).unwrap_or(0)
}

/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_screenshot_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_screenshot_width(
    handle: *const ScreenshotListHandle,
    idx: usize,
) -> u32 {
    let data = unsafe { screenshot_list_ref(handle) };
    data.items.get(idx).map(|s| s.width).unwrap_or(0)
}

/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_screenshot_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_screenshot_height(
    handle: *const ScreenshotListHandle,
    idx: usize,
) -> u32 {
    let data = unsafe { screenshot_list_ref(handle) };
    data.items.get(idx).map(|s| s.height).unwrap_or(0)
}

/// Borrowed pointer to the PNG bytes of the screenshot at `idx`, valid until
/// the list is freed. Writes the byte length to `out_len`. Returns NULL (and
/// writes 0 to `out_len`) if `idx` is out of range.
///
/// # Safety
/// `handle` must be a valid, non-null pointer returned by a
/// `liteparse_parser_screenshot_*` function and not yet freed. `out_len`
/// must be null or a valid pointer to a writable `usize`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_screenshot_bytes(
    handle: *const ScreenshotListHandle,
    idx: usize,
    out_len: *mut usize,
) -> *const u8 {
    let data = unsafe { screenshot_list_ref(handle) };
    match data.items.get(idx) {
        Some(item) => {
            if !out_len.is_null() {
                unsafe { *out_len = item.image_bytes.len() };
            }
            item.image_bytes.as_ptr()
        }
        None => {
            if !out_len.is_null() {
                unsafe { *out_len = 0 };
            }
            std::ptr::null()
        }
    }
}

/// # Safety
/// `handle` must be null or a value previously returned by a
/// `liteparse_parser_screenshot_*` function and not yet freed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_screenshot_list_free(handle: *mut ScreenshotListHandle) {
    unsafe { screenshot_list_drop(handle) };
}
