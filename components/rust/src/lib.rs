use std::{ptr::null, ffi::CString};

use ddcommon::container_id::get_container_id;

#[no_mangle]
pub extern "C" fn ddog_container_id() -> *const std::os::raw::c_char {
    match get_container_id().map(CString::new).and_then(Result::ok) {
        Some(id) => id.as_ptr(),
        None => null(),
    }
}

pub use ddtelemetry_ffi::*;