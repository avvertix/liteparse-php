//! Thread-local last-error storage for the FFI boundary.
//!
//! Every fallible `extern "C"` function calls `set_last_error` immediately
//! before returning its failure sentinel (`null` / negative). PHP reads the
//! message via `liteparse_get_last_error` right after observing the sentinel,
//! then must copy it (e.g. `FFI::string()`) before making another FFI call —
//! the returned pointer is only valid until the next `set_last_error`.

use std::cell::RefCell;
use std::ffi::{CString, c_char};

thread_local! {
    static LAST_ERROR: RefCell<Option<CString>> = const { RefCell::new(None) };
}

/// Store `msg` as the current thread's last error. Embedded NUL bytes are
/// stripped so `CString::new` cannot fail.
pub fn set_last_error(msg: impl AsRef<str>) {
    let sanitized = msg.as_ref().replace('\0', "");
    let cstr = CString::new(sanitized).unwrap_or_default();
    LAST_ERROR.with(|slot| *slot.borrow_mut() = Some(cstr));
}

pub fn clear_last_error() {
    LAST_ERROR.with(|slot| *slot.borrow_mut() = None);
}

/// Returns a pointer to the current thread's last error message, or NULL if
/// none is set. Valid until the next call that sets or clears the error on
/// this thread.
#[unsafe(no_mangle)]
pub extern "C" fn liteparse_get_last_error() -> *const c_char {
    LAST_ERROR.with(|slot| match slot.borrow().as_ref() {
        Some(cstr) => cstr.as_ptr(),
        None => std::ptr::null(),
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn liteparse_clear_last_error() {
    clear_last_error();
}
