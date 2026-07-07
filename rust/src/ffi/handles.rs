//! Opaque handle types exposed across the FFI boundary, plus the private
//! data structs they actually point at. cbindgen only ever sees the
//! zero-size marker structs below (`ParserHandle`, `ResultHandle`,
//! `ScreenshotListHandle`); PHP holds a raw pointer to one, never touching
//! its layout. The real data lives in `*Data`, allocated with
//! `Box::into_raw` and reclaimed with `Box::from_raw` in the matching
//! `_free` function.

use liteparse::config::ImageMode;
use liteparse::{LiteParse, ParseResult, ScreenshotResult};

#[repr(C)]
pub struct ParserHandle {
    _private: [u8; 0],
}

#[repr(C)]
pub struct ResultHandle {
    _private: [u8; 0],
}

#[repr(C)]
pub struct ScreenshotListHandle {
    _private: [u8; 0],
}

pub struct ParserData {
    pub parser: LiteParse,
}

pub struct ResultData {
    pub result: ParseResult,
    pub image_mode: ImageMode,
}

pub struct ScreenshotListData {
    pub items: Vec<ScreenshotResult>,
}

pub fn parser_into_handle(data: ParserData) -> *mut ParserHandle {
    Box::into_raw(Box::new(data)) as *mut ParserHandle
}

pub unsafe fn parser_ref<'a>(handle: *const ParserHandle) -> &'a ParserData {
    unsafe { &*(handle as *const ParserData) }
}

pub unsafe fn parser_drop(handle: *mut ParserHandle) {
    if !handle.is_null() {
        unsafe { drop(Box::from_raw(handle as *mut ParserData)) };
    }
}

pub fn result_into_handle(data: ResultData) -> *mut ResultHandle {
    Box::into_raw(Box::new(data)) as *mut ResultHandle
}

pub unsafe fn result_ref<'a>(handle: *const ResultHandle) -> &'a ResultData {
    unsafe { &*(handle as *const ResultData) }
}

pub unsafe fn result_drop(handle: *mut ResultHandle) {
    if !handle.is_null() {
        unsafe { drop(Box::from_raw(handle as *mut ResultData)) };
    }
}

pub fn screenshot_list_into_handle(data: ScreenshotListData) -> *mut ScreenshotListHandle {
    Box::into_raw(Box::new(data)) as *mut ScreenshotListHandle
}

pub unsafe fn screenshot_list_ref<'a>(
    handle: *const ScreenshotListHandle,
) -> &'a ScreenshotListData {
    unsafe { &*(handle as *const ScreenshotListData) }
}

pub unsafe fn screenshot_list_drop(handle: *mut ScreenshotListHandle) {
    if !handle.is_null() {
        unsafe { drop(Box::from_raw(handle as *mut ScreenshotListData)) };
    }
}
