//! Owned C-string helpers shared by every FFI function that hands a heap
//! string back to PHP. Every string returned this way must be released via
//! `liteparse_string_free` — it is allocated by Rust's global allocator via
//! `CString::into_raw`, not by libc `malloc`, so PHP must never call `free()`
//! on it directly.

use std::ffi::{CStr, CString, c_char};

/// Convert a Rust `String` into an owned, NUL-terminated C string. Embedded
/// NUL bytes are stripped (they cannot be represented in a C string and
/// would otherwise truncate the value silently on the PHP side anyway).
pub fn string_to_owned_c_char(s: impl AsRef<str>) -> *mut c_char {
    let sanitized = s.as_ref().replace('\0', "");
    CString::new(sanitized).unwrap_or_default().into_raw()
}

/// # Safety
/// `ptr` must be null or a value previously returned by one of this crate's
/// FFI functions that documents itself as returning an owned string.
pub unsafe fn c_char_to_string(ptr: *const c_char) -> Option<String> {
    if ptr.is_null() {
        return None;
    }
    Some(unsafe { CStr::from_ptr(ptr) }.to_string_lossy().into_owned())
}

/// Free a string previously returned by any `liteparse_*` function that
/// documents its return value as an owned string (e.g.
/// `liteparse_result_json`, `liteparse_result_text`).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn liteparse_string_free(ptr: *mut c_char) {
    if !ptr.is_null() {
        unsafe {
            drop(CString::from_raw(ptr));
        }
    }
}
